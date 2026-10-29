<?php

namespace RiseTechApps\FusionReport;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use RiseTechApps\FusionReport\Builders\ReportRequestBuilder;
use RiseTechApps\FusionReport\Definitions\ReportDefinition;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Managers\TemplateManager;
use RiseTechApps\FusionReport\Resources\FileResource;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use RiseTechApps\FusionReport\Resources\UsageResource;

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

    /**
     * Definição registrada com esse nome, ou null quando não há.
     */
    public function definition(string $name): ?ReportDefinition
    {
        $class = $this->registry[$name] ?? null;

        return $class === null ? null : app($class);
    }

    /**
     * Definição registrada cujo `name()` é o template informado, ou null.
     *
     * Busca pelo nome no servidor, não pela chave do registro — é o que o
     * callback traz em `template_name`. Cobre tanto `fusion-report.reports`
     * quanto o que foi registrado em runtime via `register()`.
     */
    public function definitionFor(string $templateName): ?ReportDefinition
    {
        foreach ($this->registry as $class) {
            /** @var ReportDefinition $definition */
            $definition = app($class);

            if ($definition->name() === $templateName) {
                return $definition;
            }
        }

        return null;
    }

    public function make(string $name, array $params = []): ReportRequestBuilder
    {
        $class = $this->registry[$name]
            ?? throw new InvalidArgumentException("Report definition [{$name}] not registered.");

        /** @var ReportDefinition $definition */
        $definition = app($class);

        $merged = array_replace($definition->defaultParams(), $params);

        $builder = $this->report($definition->name())
            ->params($merged)
            ->datasource($definition->datasource($merged))
            ->webhookParams($definition->webhookParams($merged))
            ->setAfterGenerate(fn ($generation, $context) => $definition->onGenerated($generation, $context));

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
            defaultLocale: $this->config['defaults']['locale'] ?? null,
            defaultFormat: $this->config['defaults']['format'] ?? null,
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
            defaultLocale: $this->config['defaults']['locale'] ?? null,
            defaultFormat: $this->config['defaults']['format'] ?? null,
        );
    }

    public function templates(): TemplateManager
    {
        return new TemplateManager($this->http);
    }

    public function file(string $fileId): FileResource
    {
        return new FileResource(['id' => $fileId], $this->http);
    }

    public function generation(string $generationId): GenerationResource
    {
        return new GenerationResource(['id' => $generationId], $this->http);
    }

    /**
     * Consumo da conta no período: plano, requisições, excedente e limite por
     * minuto. Não desconta da cota. Exige o servidor 1.1+.
     */
    public function usage(): UsageResource
    {
        return new UsageResource($this->http->get('/api/v1/usage'));
    }

    /**
     * Requisições por dia nos últimos `$days` dias (1 a 90), do mais antigo ao
     * mais recente. Não desconta da cota. Exige o servidor 1.1+.
     *
     * @return Collection<int, array{date: string, total: int, success: int, client_error: int, server_error: int}>
     */
    public function dailyUsage(int $days = 30): Collection
    {
        if ($days < 1 || $days > 90) {
            throw new InvalidArgumentException("dailyUsage() accepts 1 to 90 days, {$days} given.");
        }

        $response = $this->http->get('/api/v1/usage/daily', ['days' => $days]);

        return collect($response['days'] ?? []);
    }
}
