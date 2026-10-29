<?php

namespace RiseTechApps\FusionReport\Webhook;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RiseTechApps\FusionReport\Contracts\WebhookHandler;
use RiseTechApps\FusionReport\Events\ReportGenerationCompleted;
use RiseTechApps\FusionReport\Events\ReportGenerationFailed;
use RiseTechApps\FusionReport\Exceptions\UnknownGenerationException;
use RiseTechApps\FusionReport\FusionReportClient;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use Throwable;

class FusionReportWebhookHandler implements WebhookHandler
{
    public function __construct(
        private readonly FusionReportHttp $http,
        private readonly FusionReportClient $client,
    ) {}

    public function handle(WebhookPayload $webhook): void
    {
        // Com o log desligado nunca existe registro, e antes disso fazia o
        // handler retornar cedo — o callback virava no-op e o fluxo assíncrono
        // inteiro sumia em silêncio. Aqui os efeitos disparam mesmo assim; sem
        // tabela não há context nem dono para recuperar, e a deduplicação fica
        // por conta do cache.
        if (! config('fusion-report.log_generations', true)) {
            if ($this->alreadyProcessed($webhook, null)) {
                $this->logDuplicate($webhook);

                return;
            }

            $this->runSideEffects($webhook, null, null);

            return;
        }

        $record = FusionReportGeneration::where('generation_uuid', $webhook->uuid())->first();

        if ($record === null) {
            // Com o log ligado, registro ausente tem duas causas: o callback
            // chegou antes de generateAsync() gravar a linha (relatório rápido),
            // ou o contexto está errado (o middleware de tenancy não inicializou
            // o tenant do callback) / a geração já foi podada.
            //
            // Antes era só ignorado, e no primeiro caso a linha ficava `pending`
            // para sempre. Agora falha de forma recuperável: o servidor reentrega
            // e, na segunda tentativa, a linha já existe. Disparar aqui reagiria
            // à geração de outro contexto, então nada é escrito.
            Log::warning('[FusionReport] Callback de geração desconhecida — aguardando reentrega.', [
                'generation_uuid' => $webhook->uuid(),
                'status' => $webhook->status(),
            ]);

            throw new UnknownGenerationException("Generation [{$webhook->uuid()}] not found locally.");
        }

        // Lido ANTES do update: depois dele a linha já carrega o status novo e
        // a evidência de entrega anterior se perde. Ver alreadyProcessed().
        $knownStatus = $record->status;

        $data = ['status' => $webhook->status()];

        if ($webhook->isSuccess()) {
            $data['frp_uuid'] = $webhook->frpId();
            $data['download_urls'] = $webhook->downloads()
                ->mapWithKeys(fn (WebhookDownload $d) => [$d->format() => $d->url()])
                ->toArray();
        }

        // A escrita continua sempre acontecendo: gravar os mesmos valores de
        // novo é inofensivo, e mantém a linha correta caso a primeira entrega
        // tenha morrido no meio.
        $record->update($data);

        if ($this->alreadyProcessed($webhook, $knownStatus)) {
            $this->logDuplicate($webhook);

            return;
        }

        $this->runSideEffects($webhook, $record, $knownStatus);
    }

    /**
     * Executa os efeitos colaterais, desfazendo as marcas de "já processado"
     * quando eles falham.
     *
     * Os dois guards de alreadyProcessed() são gravados ANTES dos efeitos —
     * o status no update, a chave no Cache::add(). Sem desfazer, um
     * onWebhookReceived() que lançasse exceção (SMTP fora, storage fora) fazia
     * a reentrega do servidor e o retry do job caírem em "duplicado", e o
     * efeito nunca acontecia.
     *
     * Desfeitas as marcas, a exceção sobe: a rota responde 500 e o servidor
     * reentrega; na fila, o job é retentado. A garantia passa a ser "pelo
     * menos uma vez" — um listener que rodou antes da falha pode rodar de novo.
     */
    private function runSideEffects(WebhookPayload $webhook, ?FusionReportGeneration $record, ?string $knownStatus): void
    {
        try {
            $this->dispatchSideEffects($webhook, $record, $record?->context ?? []);
        } catch (Throwable $e) {
            Cache::forget($this->deduplicationKey($webhook));

            $record?->update(['status' => $knownStatus]);

            throw $e;
        }
    }

    /**
     * Efeitos colaterais do callback: eventos e hook da definition.
     *
     * `$record` é null quando `log_generations` está desligado — nesse modo não
     * há dono nem context persistidos para repassar.
     */
    private function dispatchSideEffects(WebhookPayload $webhook, ?FusionReportGeneration $record, array $context): void
    {
        $this->dispatchEvent($webhook, $record, $context);

        $this->dispatchToDefinition($webhook, $context);
    }

    /**
     * Este callback já foi processado antes?
     *
     * O servidor tenta de novo (1 min, 5 min, 15 min, 1 h, 6 h) sempre que o
     * 2xx não chega — inclusive quando o handler já rodou inteiro mas estourou
     * o timeout de 10s da requisição. O controller já descarta X-Webhook-Id
     * repetido; isto cobre o mesmo callback chegando com outro id. Repetir `onWebhookReceived()` e
     * os eventos produziria e-mail duplicado, job duplicado e afins.
     *
     * Dois guards, por razões diferentes:
     *
     * 1. Status da linha — durável, sobrevive a flush de cache, mas só existe
     *    com `log_generations` ligado e sofre corrida: duas entregas simultâneas
     *    leem o status antigo antes de qualquer uma gravar.
     * 2. `Cache::add()` — atômico, então fecha a corrida, e é a única memória
     *    disponível quando não há tabela. Perde valor se o cache for limpo.
     *
     * Qualquer um dos dois acusando entrega anterior basta para pular.
     * Ambos são desfeitos se os efeitos falharem — ver runSideEffects().
     */
    private function alreadyProcessed(WebhookPayload $webhook, ?string $knownStatus): bool
    {
        if ($knownStatus !== null && $knownStatus === $webhook->status()) {
            return true;
        }

        $ttl = (int) config('fusion-report.webhook.deduplication_ttl', 2880);

        // add() grava e devolve true só quando a chave ainda não existia.
        return ! Cache::add($this->deduplicationKey($webhook), true, now()->addMinutes($ttl));
    }

    private function deduplicationKey(WebhookPayload $webhook): string
    {
        return "fusion-report:webhook:{$webhook->uuid()}:{$webhook->status()}";
    }

    private function logDuplicate(WebhookPayload $webhook): void
    {
        Log::info('[FusionReport] Callback duplicado ignorado (efeitos colaterais já executados).', [
            'generation_uuid' => $webhook->uuid(),
            'status' => $webhook->status(),
        ]);
    }

    /**
     * O corpo do webhook é o próprio ReportGeneration serializado — mesma forma
     * que `GET /api/v1/generations/{id}` devolve —, então dá para reaproveitar
     * o GenerationResource nos eventos.
     */
    private function dispatchEvent(WebhookPayload $webhook, ?FusionReportGeneration $record, array $context): void
    {
        if (! $webhook->isSuccess() && ! $webhook->isFailed()) {
            return;
        }

        $generation = new GenerationResource($webhook->toArray(), $this->http);
        $owner = $record?->loggable;

        Event::dispatch($webhook->isFailed()
            ? new ReportGenerationFailed($generation, $context, $owner, $webhook->error())
            : new ReportGenerationCompleted($generation, $context, $owner));
    }

    /**
     * Consulta o registry do client, não só `fusion-report.reports`: uma
     * definition registrada em runtime via `FusionReport::register()` também
     * recebe o callback.
     */
    private function dispatchToDefinition(WebhookPayload $webhook, array $context = []): void
    {
        $templateName = $webhook->templateName();

        if ($templateName === null) {
            return;
        }

        $this->client->definitionFor($templateName)?->onWebhookReceived($webhook, $context);
    }
}
