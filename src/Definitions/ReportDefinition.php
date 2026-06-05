<?php

namespace RiseTechApps\FusionReport\Definitions;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use RiseTechApps\FusionReport\Webhook\WebhookPayload;

abstract class ReportDefinition
{
    abstract public function template(): string;

    abstract public function datasource(array $params = []): Datasource;

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

    /**
     * Caminho absoluto do arquivo .jrxml a ser enviado ao servidor.
     */
    abstract public function templatePath(): ?string;

    /**
     * Caminho absoluto do .zip de recursos do template (imagens, sub-reports,
     * fontes). Retorne null quando o relatório não possui recursos.
     */
    abstract public function resourcesPath(): ?string;

    /**
     * Descrição do template exibida no servidor.
     */
    abstract public function description(): ?string;

    public function onGenerated(GenerationResource $generation, array $context = []): void {}

    public function onWebhookReceived(WebhookPayload $payload, array $context = []): void {}
}
