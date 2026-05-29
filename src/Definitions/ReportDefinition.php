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

    public function owner(): ?Model
    {
        return auth()->user();
    }

    public function onGenerated(GenerationResource $generation, array $context = []): void {}

    public function onWebhookReceived(WebhookPayload $payload): void {}
}
