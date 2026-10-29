<?php

namespace RiseTechApps\FusionReport\Events;

use RiseTechApps\FusionReport\Webhook\WebhookPayload;
use Throwable;

/**
 * O app desistiu de processar o callback de uma geração.
 *
 * Diferente de `ReportGenerationFailed`: aqui quem falhou foi o app, não o
 * servidor — o relatório pode estar pronto, e `$webhook->downloads()` traz as
 * URLs para entregá-lo por outro caminho.
 *
 * Só no modo com fila (`webhook.queue`), quando `ProcessFusionReportWebhook`
 * esgota as tentativas. Inline quem reentrega é o servidor, e o app não fica
 * sabendo quando ele desiste.
 *
 * Sem `$context`/`$owner`: buscá-los exigiria a linha em
 * `fusion_report_generations`, e uma das causas de chegar aqui é ela não
 * existir. Quem precisar, consulta pelo `$webhook->uuid()`.
 */
final class ReportWebhookFailed
{
    public function __construct(
        public readonly WebhookPayload $webhook,
        public readonly ?Throwable $exception = null,
    ) {}
}
