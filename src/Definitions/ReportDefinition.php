<?php

namespace RiseTechApps\FusionReport\Definitions;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use RiseTechApps\FusionReport\Webhook\WebhookPayload;

abstract class ReportDefinition
{
    /**
     * Nome do relatório no servidor (parte da identidade name + theme).
     */
    abstract public function name(): string;

    abstract public function datasource(array $params = []): Datasource;

    /**
     * Topics available for this report.
     *
     * On the server the identity of a template is (name + theme), so each
     * Theme is a distinct template, with its own .jrxml file and resources.
     *
     * @return array<int, ThemeFusion>
     */
    abstract public function themes(): array;

    public function defaultParams(): array
    {
        return [];
    }

    public function protection(): ?ReportProtection
    {
        return null;
    }

    /**
     * Parameters to be appended to the webhook base URL (query string).
     * Useful for loading context that the callback needs — e.g., tenant_id.
     *
     * @param array<string, mixed> $params Parameters already merged from generation
     * @return array<string, scalar>
     */
    public function webhookParams(array $params = []): array
    {
        return [];
    }

    public function owner(): ?Model
    {
        return auth()->user();
    }

    /**
     * Executado após geração SÍNCRONA (`generate()`), com o relatório pronto.
     *
     * NÃO é chamado por `generateAsync()`: ali o servidor apenas enfileirou o
     * job, `$generation->files()` viria vazio e não há o que entregar. O
     * equivalente assíncrono é `onWebhookReceived()`.
     *
     * Para reagir ao enfileiramento em si, escute o evento
     * `ReportGenerationStarted`, disparado nos dois modos.
     */
    public function onGenerated(GenerationResource $generation, array $context = []): void {}

    /**
     * Executado quando o callback da geração assíncrona chega — é aqui que o
     * resultado de `generateAsync()` aparece.
     */
    public function onWebhookReceived(WebhookPayload $payload, array $context = []): void {}
}
