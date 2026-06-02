<?php

namespace RiseTechApps\FusionReport\Builders;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;
use RiseTechApps\FusionReport\Datasources\NoDatasource;
use RiseTechApps\FusionReport\Definitions\ReportProtection;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;
use RiseTechApps\FusionReport\Resources\GenerationResource;

class ReportRequestBuilder
{
    private ?string $theme = null;
    private array $formats = [];
    private array $params = [];
    private Datasource $datasource;
    private ?string $locale = null;
    private ?ReportProtection $protection = null;
    private ?string $reportName = null;
    private ?string $fontsBase64 = null;
    private ?string $resourcesBase64 = null;
    private ?\Closure $afterGenerate = null;
    private array $context = [];
    private ?Model $owner = null;
    private array $webhookParams = [];

    public function __construct(
        private readonly FusionReportHttp $http,
        private readonly ?string $template = null,
        private readonly ?string $templateId = null,
        private readonly array $defaultParams = [],
        private readonly ?string $defaultWebhook = null,
    ) {
        $this->datasource = NoDatasource::make();
    }

    public function theme(string $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function format(string|array $format): static
    {
        $this->formats = array_merge($this->formats, (array) $format);

        return $this;
    }

    public function params(array $params): static
    {
        $this->params = array_merge($this->params, $params);

        return $this;
    }

    public function datasource(Datasource $datasource): static
    {
        $this->datasource = $datasource;

        return $this;
    }

    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function protection(ReportProtection $protection): static
    {
        $this->protection = $protection;

        return $this;
    }

    public function reportName(string $name): static
    {
        $this->reportName = $name;

        return $this;
    }

    public function fontsBase64(string $base64): static
    {
        $this->fontsBase64 = $base64;

        return $this;
    }

    public function resourcesBase64(string $base64): static
    {
        $this->resourcesBase64 = $base64;

        return $this;
    }

    public function context(array $context): static
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    public function for(Model $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function webhookParams(array $params): static
    {
        $this->webhookParams = array_merge($this->webhookParams, $params);

        return $this;
    }

    public function setAfterGenerate(\Closure $callback): static
    {
        $this->afterGenerate = $callback;

        return $this;
    }

    public function generate(): GenerationResource
    {
        $endpoint = $this->templateId
            ? "/api/v1/reports/{$this->templateId}/generate"
            : '/api/v1/generate';

        $generation = new GenerationResource(
            $this->http->post($endpoint, $this->buildPayload()),
            $this->http,
        );

        if (config('fusion-report.log_generations', true)) {
            FusionReportGeneration::createFromGeneration($generation, $this->owner);
        }

        if ($this->afterGenerate) {
            ($this->afterGenerate)($generation, $this->context);
        }

        return $generation;
    }

    public function generateAsync(?string $webhook = null): GenerationResource
    {
        $endpoint = $this->templateId
            ? "/api/v1/reports/{$this->templateId}/async"
            : '/api/v1/async';

        $payload = $this->buildPayload();

        $webhookUrl = $this->buildWebhookUrl($webhook ?? $this->defaultWebhook);
        if ($webhookUrl !== null) {
            $payload['webhook_url'] = $webhookUrl;
        }

        $response = $this->http->post($endpoint, $payload);

        $generation = new GenerationResource($response, $this->http);

        if (config('fusion-report.log_generations', true)) {
            FusionReportGeneration::createFromGeneration($generation, $this->owner);
        }

        return $generation;
    }

    private function buildWebhookUrl(?string $base): ?string
    {
        if ($base === null || empty($this->webhookParams)) {
            return $base;
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . http_build_query($this->webhookParams);
    }

    private function buildPayload(): array
    {
        $datasourceType = $this->datasource->type();
        $datasourceConfig = $this->datasource->config();

        return array_filter([
            'name'              => $this->templateId ? null : $this->template,
            'theme'             => $this->templateId ? null : $this->theme,
            'format'            => $this->formats ?: null,
            'params'            => $this->serializeParams(),
            'datasource_type'   => $datasourceType !== 'none' ? $datasourceType : null,
            'datasource_config' => $datasourceConfig ?: null,
            'locale'            => $this->locale,
            'report_name'       => $this->reportName,
            'fonts_base64'      => $this->fontsBase64,
            'resources_base64'  => $this->resourcesBase64,
            ...($this->protection?->toArray() ?? []),
        ]);
    }

    private function serializeParams(): ?array
    {
        $merged = array_merge($this->defaultParams, $this->params);

        if (empty($merged)) {
            return null;
        }

        return array_map(
            fn($key, $value) => "{$key}={$value}",
            array_keys($merged),
            $merged,
        );
    }
}
