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
     * Temas disponíveis para este relatório.
     *
     * No servidor a identidade de um template é (name + theme), portanto cada
     * tema é um template distinto, com seu próprio arquivo .jrxml e resources.
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
     * Parâmetros a serem anexados à URL base do webhook (query string).
     * Útil para carregar contexto que o callback precisa — ex.: tenant_id.
     *
     * @param array<string, mixed> $params Parâmetros já mesclados da geração
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

    public function onGenerated(GenerationResource $generation, array $context = []): void {}

    public function onWebhookReceived(WebhookPayload $payload, array $context = []): void {}
}
