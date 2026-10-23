<?php

namespace RiseTechApps\FusionReport\Webhook;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RiseTechApps\FusionReport\Contracts\WebhookHandler;
use RiseTechApps\FusionReport\Definitions\ReportDefinition;
use RiseTechApps\FusionReport\Events\ReportGenerationCompleted;
use RiseTechApps\FusionReport\Events\ReportGenerationFailed;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;
use RiseTechApps\FusionReport\Resources\GenerationResource;

class FusionReportWebhookHandler implements WebhookHandler
{
    public function __construct(private readonly FusionReportHttp $http) {}

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

            $this->dispatchSideEffects($webhook, null, []);

            return;
        }

        $record = FusionReportGeneration::where('generation_uuid', $webhook->uuid())->first();

        if ($record === null) {
            // Com o log ligado, registro ausente não é normal: costuma ser
            // contexto errado (o middleware de tenancy não inicializou o tenant
            // do callback) ou uma geração já podada. Disparar aqui reagiria à
            // geração de outro contexto, então só avisa.
            Log::warning('[FusionReport] Callback de geração desconhecida ignorado.', [
                'generation_uuid' => $webhook->uuid(),
                'status'          => $webhook->status(),
            ]);

            return;
        }

        // Lido ANTES do update: depois dele a linha já carrega o status novo e
        // a evidência de entrega anterior se perde. Ver alreadyProcessed().
        $knownStatus = $record->status;

        $data = ['status' => $webhook->status()];

        if ($webhook->isSuccess()) {
            $data['frp_uuid']      = $webhook->frpId();
            $data['download_urls'] = $webhook->downloads()
                ->mapWithKeys(fn(WebhookDownload $d) => [$d->format() => $d->url()])
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

        $this->dispatchSideEffects($webhook, $record, $record->context ?? []);
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
     * O servidor reentrega até 3 vezes (backoff 10s/60s/180s) sempre que a
     * resposta não chega — inclusive quando o handler já rodou inteiro mas
     * estourou o timeout de 15s da requisição. Repetir `onWebhookReceived()` e
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
     */
    private function alreadyProcessed(WebhookPayload $webhook, ?string $knownStatus): bool
    {
        if ($knownStatus !== null && $knownStatus === $webhook->status()) {
            return true;
        }

        $ttl = (int) config('fusion-report.webhook.deduplication_ttl', 1440);

        $key = "fusion-report:webhook:{$webhook->uuid()}:{$webhook->status()}";

        // add() grava e devolve true só quando a chave ainda não existia.
        return ! Cache::add($key, true, now()->addMinutes($ttl));
    }

    private function logDuplicate(WebhookPayload $webhook): void
    {
        Log::info('[FusionReport] Callback duplicado ignorado (efeitos colaterais já executados).', [
            'generation_uuid' => $webhook->uuid(),
            'status'          => $webhook->status(),
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
        $owner      = $record?->loggable;

        Event::dispatch($webhook->isFailed()
            ? new ReportGenerationFailed($generation, $context, $owner, $webhook->error())
            : new ReportGenerationCompleted($generation, $context, $owner));
    }

    private function dispatchToDefinition(WebhookPayload $webhook, array $context = []): void
    {
        $templateName = $webhook->templateName();

        if ($templateName === null) {
            return;
        }

        $reports = config('fusion-report.reports', []);

        $definitionClass = collect($reports)->first(
            fn($class) => new $class()->name() === $templateName
        );

        if ($definitionClass === null) {
            return;
        }

        /** @var ReportDefinition $definition */
        $definition = app($definitionClass);
        $definition->onWebhookReceived($webhook, $context);
    }
}
