<?php

namespace RiseTechApps\FusionReport\Builders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;
use RiseTechApps\FusionReport\Events\ReportGenerationCompleted;
use RiseTechApps\FusionReport\Events\ReportGenerationFailed;
use RiseTechApps\FusionReport\Events\ReportGenerationStarted;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
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
        private readonly ?string $defaultTheme = null,
        private readonly ?string $defaultLocale = null,
        private readonly string|array|null $defaultFormat = null,
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
        $this->formats = array_values(array_unique(
            array_merge($this->formats, static::normalizeFormats($format))
        ));

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

    /**
     * Callback executado ao fim de `generate()`.
     *
     * Só vale para o modo síncrono — `generateAsync()` não o executa, porque
     * naquele ponto o relatório ainda não existe. Ver `ReportDefinition::onGenerated()`.
     */
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

        $context = $this->persistedContext();

        if (config('fusion-report.log_generations', true)) {
            FusionReportGeneration::createFromGeneration($generation, $this->owner, $context);
        }

        Event::dispatch(new ReportGenerationStarted($generation, $context, $this->owner));

        Event::dispatch($generation->isFailed()
            ? new ReportGenerationFailed($generation, $context, $this->owner, $generation->errorMessage())
            : new ReportGenerationCompleted($generation, $context, $this->owner));

        if ($this->afterGenerate) {
            ($this->afterGenerate)($generation, $context);
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

        $context = $this->persistedContext();

        if (config('fusion-report.log_generations', true)) {
            FusionReportGeneration::createFromGeneration($generation, $this->owner, $context);
        }

        Event::dispatch(new ReportGenerationStarted($generation, $context, $this->owner));

        return $generation;
    }

    private function effectiveLocale(): ?string
    {
        return $this->locale ?? $this->defaultLocale;
    }

    /**
     * Formatos da geração, caindo no default do config quando nenhum foi
     * informado. O servidor exige `format` obrigatório — sem o default, toda
     * geração sem `->format()` explícito voltava 422.
     */
    private function effectiveFormats(): array
    {
        return $this->formats ?: static::normalizeFormats($this->defaultFormat);
    }

    /**
     * Aceita 'pdf', 'pdf,xlsx' ou ['pdf', 'xlsx'], normalizando para a lista
     * minúscula que o servidor valida.
     */
    private static function normalizeFormats(string|array|null $format): array
    {
        if ($format === null) {
            return [];
        }

        $items = is_string($format) ? explode(',', $format) : $format;

        $items = array_map(fn($item) => strtolower(trim((string) $item)), $items);

        return array_values(array_unique(array_filter($items, fn($item) => $item !== '')));
    }

    /**
     * Context persistido com a geração. Injeta o locale efetivo (resolvido do
     * builder ou do config) sem sobrescrever um 'locale' já informado pelo caller.
     */
    private function persistedContext(): array
    {
        $context = $this->context;

        $locale = $this->effectiveLocale();
        if ($locale !== null && ! array_key_exists('locale', $context)) {
            $context['locale'] = $locale;
        }

        return $context;
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
            'theme'             => $this->templateId ? null : ($this->theme ?? $this->defaultTheme),
            'format'            => $this->effectiveFormats() ?: null,
            'params'            => $this->serializeParams(),
            'datasource_type'   => $datasourceType !== 'none' ? $datasourceType : null,
            'datasource_config' => $datasourceConfig ?: null,
            'locale'            => $this->effectiveLocale(),
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
            fn($key, $value) => "{$key}=" . $this->stringifyParam($key, $value),
            array_keys($merged),
            $merged,
        );
    }

    /**
     * Converte o valor de um parâmetro para a forma `key=value` que o servidor
     * espera.
     *
     * A interpolação direta quebrava com array ("Array to string conversion")
     * e produzia `KEY=` para `false`, indistinguível de string vazia.
     *
     * @throws FusionReportException quando o valor não tem representação textual
     */
    private function stringifyParam(string $key, mixed $value): string
    {
        return match (true) {
            $value === null                     => '',
            is_bool($value)                     => $value ? 'true' : 'false',
            is_scalar($value)                   => (string) $value,
            $value instanceof \BackedEnum       => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \Stringable       => (string) $value,
            is_array($value)                    => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            default                             => throw new FusionReportException(
                sprintf('Report param [%s] of type %s cannot be serialized.', $key, get_debug_type($value)),
            ),
        };
    }
}
