<?php

namespace RiseTechApps\FusionReport\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RiseTechApps\FusionReport\Contracts\WebhookHandler;
use RiseTechApps\FusionReport\Exceptions\MalformedWebhookException;
use RiseTechApps\FusionReport\Exceptions\WebhookSignatureException;
use RiseTechApps\FusionReport\Jobs\ProcessFusionReportWebhook;
use RiseTechApps\FusionReport\Webhook\WebhookPayload;

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
                    . 'Defina FUSION_REPORT_WEBHOOK_SECRET ou, apenas em desenvolvimento, '
                    . 'FUSION_REPORT_WEBHOOK_REQUIRE_SIGNATURE=false.');

                return response()->json(['error' => 'Webhook signature not configured.'], 503);
            }

            Log::warning('[FusionReport] Webhook aceito SEM verificação de assinatura '
                . '(require_signature=false). Não use esta configuração em produção.');
        }

        try {
            $webhook = WebhookPayload::fromRequest($request, $secret);

            // Valida a forma AQUI, antes de qualquer enfileiramento: depois de
            // responder 200 não há mais como avisar o servidor de que o payload
            // era inválido. São os dois campos que MalformedWebhookException
            // cobre.
            $webhook->uuid();
            $webhook->status();
        } catch (WebhookSignatureException $e) {
            Log::warning('[FusionReport] Webhook signature failure: ' . $e->getMessage(), [
                'raw_body' => $request->getContent(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        } catch (MalformedWebhookException $e) {
            // 422 em vez de 500: reprocessar não resolve, então o servidor para
            // de retentar em vez de repetir o mesmo payload inválido.
            Log::warning('[FusionReport] Malformed webhook payload: ' . $e->getMessage(), [
                'raw_body' => $request->getContent(),
            ]);

            return response()->json(['error' => 'Malformed payload'], 422);
        }

        $queue = config('fusion-report.webhook.queue', false);

        if ($queue !== false && $queue !== null) {
            // Enfileirado a partir da requisição, onde o middleware já
            // estabeleceu o contexto — pacotes de tenancy com suporte a fila
            // propagam esse contexto para o job.
            $job = ProcessFusionReportWebhook::dispatch($webhook->toArray());

            if (is_string($queue)) {
                $job->onQueue($queue);
            }

            return response()->json(['ok' => true, 'queued' => true]);
        }

        $this->handler->handle($webhook);

        return response()->json(['ok' => true]);
    }
}
