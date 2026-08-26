<?php

namespace RiseTechApps\FusionReport\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RiseTechApps\FusionReport\Contracts\WebhookHandler;
use RiseTechApps\FusionReport\Webhook\WebhookPayload;

/**
 * Processa o callback fora do ciclo da requisição.
 *
 * O servidor concede 15s para a entrega e reentrega até 3 vezes quando não
 * recebe resposta. Processando inline, um `onWebhookReceived()` que manda
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

    public function __construct(private readonly array $payload) {}

    public function handle(WebhookHandler $handler): void
    {
        $handler->handle(WebhookPayload::fromArray($this->payload));
    }
}
