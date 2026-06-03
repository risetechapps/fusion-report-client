<?php

namespace RiseTechApps\FusionReport;

use InvalidArgumentException;
use RiseTechApps\FusionReport\Builders\ReportRequestBuilder;
use RiseTechApps\FusionReport\Definitions\ReportDefinition;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Managers\JasperManager;
use RiseTechApps\FusionReport\Managers\TemplateManager;
use RiseTechApps\FusionReport\Resources\FileResource;
use RiseTechApps\FusionReport\Resources\GenerationResource;

class FusionReportClient
{
    /** @var array<string, class-string<ReportDefinition>> */
    private array $registry = [];

    public function __construct(
        private readonly FusionReportHttp $http,
        private readonly array $config = [],
    ) {}

    /** @param class-string<ReportDefinition> $definition */
    public function register(string $name, string $definition): void
    {
        $this->registry[$name] = $definition;
    }

    public function make(string $name, array $params = []): ReportRequestBuilder
    {
        $class = $this->registry[$name]
            ?? throw new InvalidArgumentException("Report definition [{$name}] not registered.");

        /** @var ReportDefinition $definition */
        $definition = new $class();

        $merged = array_merge($definition->defaultParams(), $params);

        $builder = $this->report($definition->template())
            ->params($merged)
            ->datasource($definition->datasource($merged))
            ->webhookParams($definition->webhookParams($merged))
            ->setAfterGenerate(fn($generation, $context) => $definition->onGenerated($generation, $context));

        if ($protection = $definition->protection()) {
            $builder->protection($protection);
        }

        if ($owner = $definition->owner()) {
            $builder->for($owner);
        }

        return $builder;
    }

    public function report(string $template): ReportRequestBuilder
    {
        return new ReportRequestBuilder(
            $this->http,
            template: $template,
            defaultParams: $this->config['defaults']['params'] ?? [],
            defaultWebhook: $this->config['webhook']['url'] ?? null,
            defaultTheme: $this->config['defaults']['theme'] ?? null,
        );
    }

    public function reportById(string $id): ReportRequestBuilder
    {
        return new ReportRequestBuilder(
            $this->http,
            templateId: $id,
            defaultParams: $this->config['defaults']['params'] ?? [],
            defaultWebhook: $this->config['webhook']['url'] ?? null,
            defaultTheme: $this->config['defaults']['theme'] ?? null,
        );
    }

    public function templates(): TemplateManager
    {
        return new TemplateManager($this->http);
    }

    public function jasper(): JasperManager
    {
        return new JasperManager($this->http);
    }

    public function file(string $fileId): FileResource
    {
        return new FileResource(['id' => $fileId], $this->http);
    }

    public function generation(string $generationId): GenerationResource
    {
        return new GenerationResource(['id' => $generationId], $this->http);
    }
}
