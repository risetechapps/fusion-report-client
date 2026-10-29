<?php

namespace RiseTechApps\FusionReport\Http\Controllers;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RiseTechApps\FusionReport\Contracts\WebhookHandler;
use RiseTechApps\FusionReport\Exceptions\MalformedWebhookException;
use RiseTechApps\FusionReport\Exceptions\UnknownGenerationException;
use RiseTechApps\FusionReport\Exceptions\WebhookSignatureException;
use RiseTechApps\FusionReport\Jobs\ProcessFusionReportWebhook;
use RiseTechApps\FusionReport\Webhook\WebhookPayload;
use Throwable;

class WebhookController extends Controller
{
    public function __construct(private readonly WebhookHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('fusion-report.webhook.secret') ?: null;

        if ($secret === null) {
            // Falha fechada: sem segredo não há como distinguir o servidor de
            // qualquer outro chamador, e o handler escreve no banco e dispara
            // eventos da aplicação.
            if (config('fusion-report.webhook.require_signature', true)) {
                Log::critical('[FusionReport] Webhook rejeitado: webhook.secret não configurado. '
                    .'Defina FUSION_REPORT_WEBHOOK_SECRET ou, apenas em desenvolvimento, '
                    .'FUSION_REPORT_WEBHOOK_REQUIRE_SIGNATURE=false.');

                return response()->json(['error' => 'Webhook signature not configured.'], 503);
            }

            Log::warning('[FusionReport] Webhook aceito SEM verificação de assinatura '
                .'(require_signature=false). Não use esta configuração em produção.');
        }

        try {
            $webhook = WebhookPayload::fromRequest($request, $secret);

            // O teste do painel só prova que a URL e o segredo batem — já
            // provado acima. Não é geração: sem `status`, cairia no 422 e o
            // painel mostraria falha com tudo certo.
            if ($webhook->isTest()) {
                Log::info('[FusionReport] Webhook de teste recebido.', ['event_id' => $webhook->toArray()['id'] ?? null]);

                return response()->json(['ok' => true, 'test' => true]);
            }

            // Valida a forma AQUI, antes de qualquer enfileiramento: depois de
            // responder 200 não há mais como avisar o servidor de que o payload
            // era inválido. São os dois campos que MalformedWebhookException
            // cobre.
            $webhook->uuid();
            $webhook->status();
        } catch (WebhookSignatureException $e) {
            // Sem o corpo: veio de quem não provou ser o servidor, e gravá-lo
            // inteiro deixava qualquer chamador encher o log com o que quisesse.
            Log::warning('[FusionReport] Webhook signature failure: '.$e->getMessage(), [
                'ip' => $request->ip(),
                'body_size' => strlen($request->getContent()),
            ]);

            // `reason` diz ao painel do servidor o que corrigir: header que não
            // chegou (proxy, rota errada) ou segredo diferente.
            return response()->json(['error' => 'Unauthorized', 'reason' => $e->reason()], 401);
        } catch (MalformedWebhookException $e) {
            // 422 em vez de 500: reprocessar não resolve, então o servidor para
            // de retentar em vez de repetir o mesmo payload inválido.
            //
            // Só as chaves, não os valores: o corpo traz as URLs de download dos
            // relatórios, que dão acesso ao arquivo a quem lê o log.
            Log::warning('[FusionReport] Malformed webhook payload: '.$e->getMessage(), [
                'ip' => $request->ip(),
                'body_size' => strlen($request->getContent()),
                'keys' => array_keys($request->json()->all()),
            ]);

            return response()->json(['error' => 'Malformed payload'], 422);
        }

        // O servidor repete o mesmo X-Webhook-Id nas novas tentativas e no
        // "Reenviar" do painel. Respondendo 2xx, ele para de tentar.
        if (! $this->claimDelivery($webhook)) {
            Log::info('[FusionReport] Entrega repetida ignorada.', ['delivery_id' => $webhook->deliveryId()]);

            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $queue = config('fusion-report.webhook.queue', false);

        try {
            if ($queue !== false && $queue !== null) {
                // Enfileirado a partir da requisição, onde o middleware já
                // estabeleceu o contexto — pacotes de tenancy com suporte a fila
                // propagam esse contexto para o job.
                $job = new ProcessFusionReportWebhook($webhook->toArray(), $webhook->deliveryId());

                if (is_string($queue)) {
                    $job->onQueue($queue);
                }

                // Despacho imediato (não PendingDispatch): uma fila fora do ar
                // cai no catch abaixo e libera a entrega para a nova tentativa.
                app(Dispatcher::class)->dispatch($job);

                return response()->json(['ok' => true, 'queued' => true]);
            }

            $this->handler->handle($webhook);
        } catch (UnknownGenerationException) {
            $this->releaseDelivery($webhook);

            // 503 e não 200: o callback costuma chegar antes de generateAsync()
            // gravar a linha. Respondendo erro, o servidor reentrega e a linha
            // já existe. O handler já registrou o aviso.
            return response()->json(['error' => 'Unknown generation'], 503);
        } catch (Throwable $e) {
            // Sem liberar, a nova tentativa do servidor cairia em "repetida"
            // e o callback nunca seria processado.
            $this->releaseDelivery($webhook);

            throw $e;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Marca a entrega como recebida. False quando o id já foi visto.
     *
     * `Cache::add()` é atômico: duas tentativas simultâneas não passam as duas.
     * Sem `X-Webhook-Id` não há o que comparar, e sobra a deduplicação por
     * geração + status do handler.
     */
    private function claimDelivery(WebhookPayload $webhook): bool
    {
        $key = $webhook->deliveryKey();

        if ($key === null) {
            return true;
        }

        $ttl = (int) config('fusion-report.webhook.deduplication_ttl', 2880);

        return Cache::add($key, true, now()->addMinutes($ttl));
    }

    private function releaseDelivery(WebhookPayload $webhook): void
    {
        if (($key = $webhook->deliveryKey()) !== null) {
            Cache::forget($key);
        }
    }
}
