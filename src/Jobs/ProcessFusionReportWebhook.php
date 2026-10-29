<?php

namespace RiseTechApps\FusionReport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use RiseTechApps\FusionReport\Contracts\WebhookHandler;
use RiseTechApps\FusionReport\Events\ReportWebhookFailed;
use RiseTechApps\FusionReport\Webhook\WebhookPayload;
use Throwable;

/**
 * Processa o callback fora do ciclo da requisição.
 *
 * O servidor concede 10s para a entrega e tenta de novo quando não recebe
 * 2xx. Processando inline, um `onWebhookReceived()` que manda
 * e-mail ou sobe arquivo facilmente ultrapassa esse limite — e aí o servidor
 * registra "desisti de entregar" para um callback que na verdade funcionou.
 *
 * Enfileirando, o 200 sai em milissegundos e o trabalho pesado deixa de
 * competir com o timeout.
 *
 * O payload trafega como array (não como WebhookPayload) porque é o que já
 * chegou serializado do servidor, e assim o job não carrega objeto algum.
 */
class ProcessFusionReportWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    public function __construct(
        private readonly array $payload,
        private readonly ?string $deliveryId = null,
    ) {}

    public function handle(WebhookHandler $handler): void
    {
        $handler->handle(WebhookPayload::fromArray($this->payload, $this->deliveryId));
    }

    /**
     * Chamado quando as tentativas acabam.
     *
     * Com a fila ligada a rota já respondeu 200, então o servidor não reentrega:
     * este é o fim da linha do callback. O worker já reporta a exceção de cada
     * tentativa, mas sem dizer qual geração foi perdida — aqui fica um registro
     * único, com o que é preciso para achar o job no failed_jobs e rodar
     * `queue:retry`.
     *
     * Só campos de identificação no log: o payload traz as URLs de download.
     * O payload completo vai no evento, para o app poder reagir.
     */
    public function failed(?Throwable $e): void
    {
        Log::error('[FusionReport] Callback descartado após todas as tentativas.', [
            'generation_uuid' => $this->payload['id'] ?? null,
            'status' => $this->payload['status'] ?? null,
            'template_name' => $this->payload['template_name'] ?? null,
            'error' => $e?->getMessage(),
        ]);

        $webhook = WebhookPayload::fromArray($this->payload, $this->deliveryId);

        // Libera o X-Webhook-Id: sem isso, o "Reenviar" do painel cairia em
        // "entrega repetida" e o callback perdido não teria mais como voltar.
        if (($key = $webhook->deliveryKey()) !== null) {
            Cache::forget($key);
        }

        Event::dispatch(new ReportWebhookFailed($webhook, $e));
    }
}
