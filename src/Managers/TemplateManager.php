<?php

namespace RiseTechApps\FusionReport\Managers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Exceptions\ReportNotFoundException;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use RiseTechApps\FusionReport\Resources\TemplateResource;

class TemplateManager
{
    /** Máximo que o servidor aceita em `per_page`. */
    private const PER_PAGE = 100;

    /** 100 mil templates — muito acima de qualquer plano. */
    private const MAX_PAGES = 1000;

    public function __construct(private readonly FusionReportHttp $http) {}

    /**
     * Lista todos os templates registrados, percorrendo todas as páginas.
     *
     * O servidor responde `data.reports` com o paginator serializado
     * (`{current_page, data: [...], last_page, ...}`), então os itens ficam um
     * nível abaixo. Sem paginar, o sync trataria templates das páginas
     * seguintes como não registrados e os reenviaria.
     *
     * O laço não confia só no `last_page` da resposta: para na primeira
     * página vazia e nunca passa de MAX_PAGES. Antes, um `last_page` absurdo
     * (bug no servidor, proxy) virava uma requisição por página e travava o sync.
     *
     * @return Collection<int, TemplateResource>
     *
     * @throws FusionReportException quando o servidor anuncia mais de MAX_PAGES páginas
     */
    public function list(): Collection
    {
        $items = collect();
        $page = 1;

        do {
            if ($page > self::MAX_PAGES) {
                throw new FusionReportException(sprintf(
                    'Template listing exceeded %d pages (server reported last_page = %d).', self::MAX_PAGES, $lastPage,
                ));
            }

            $response = $this->http->get('/api/v1/reports', [
                'page' => $page,
                'per_page' => self::PER_PAGE,
            ]);

            $paginator = $response['reports'] ?? $response;
            $pageItems = $this->items($paginator);

            $items = $items->concat($pageItems);

            $lastPage = (int) ($paginator['last_page'] ?? 1);
            $page++;
        } while ($pageItems !== [] && $page <= $lastPage);

        return $items->map(fn (array $item) => new TemplateResource($item));
    }

    /**
     * Extrai a lista de itens de um payload que pode vir paginado
     * (`{current_page, data: [...], ...}`) ou já como array simples.
     */
    private function items(array $payload): array
    {
        $items = $payload['data'] ?? $payload;

        return is_array($items) && array_is_list($items) ? $items : [];
    }

    /**
     * Limite de templates do plano e quantos a conta já tem.
     *
     * `limit` é null quando o plano não tem limite. Vem na mesma resposta da
     * listagem; pede uma página de 1 item só para ler os dois campos.
     *
     * @return array{limit: ?int, count: int}
     */
    public function quota(): array
    {
        $response = $this->http->get('/api/v1/reports', ['per_page' => 1]);

        $limit = $response['template_limit'] ?? null;

        return [
            'limit' => is_numeric($limit) ? (int) $limit : null,
            'count' => (int) ($response['template_count'] ?? 0),
        ];
    }

    public function find(string $id): TemplateResource
    {
        $response = $this->http->get('/api/v1/reports/'.rawurlencode($id));

        return new TemplateResource($response);
    }

    public function upload(string $filePath, string $name, string $theme = 'default', string $description = '', ?string $resourcesZipPath = null): TemplateResource
    {
        $response = $this->http->postMultipart(
            '/api/v1/reports',
            array_filter(['name' => $name, 'theme' => $theme, 'description' => $description]),
            $filePath,
            basename($filePath),
            $resourcesZipPath,
        );

        return new TemplateResource($response);
    }

    /**
     * Edita o template. Todos os campos são opcionais: sem `$filePath` o
     * `.jrxml` atual fica, e dá para trocar só a descrição ou só os resources.
     */
    public function update(string $id, ?string $filePath = null, ?string $name = null, ?string $theme = null, ?string $description = null, ?string $resourcesZipPath = null): TemplateResource
    {
        $fields = array_filter(
            ['name' => $name, 'theme' => $theme, 'description' => $description],
            fn ($v) => $v !== null,
        );

        $response = $this->http->postMultipart(
            '/api/v1/reports/'.rawurlencode($id),
            $fields,
            $filePath,
            resourcesPath: $resourcesZipPath,
        );

        return new TemplateResource($response);
    }

    /**
     * Edita o template localizado por nome + `$theme` (padrão `default` no
     * servidor). Todos os campos são opcionais, como em `update()`.
     *
     * `$theme` vai na query string: é o que o servidor usa para localizar. No
     * corpo ele é o tema NOVO — mandado ali, o servidor localizava o `default`
     * e renomeava o tema dele, sobrescrevendo o `.jrxml` do tema errado.
     */
    public function updateByName(string $name, ?string $filePath = null, ?string $theme = null, ?string $description = null, ?string $resourcesZipPath = null): TemplateResource
    {
        $fields = array_filter(
            ['description' => $description],
            fn ($v) => $v !== null,
        );

        $response = $this->http->postMultipart(
            '/api/v1/reports/name/'.rawurlencode($name),
            $fields,
            $filePath,
            resourcesPath: $resourcesZipPath,
            query: array_filter(['theme' => $theme], fn ($v) => $v !== null),
        );

        return new TemplateResource($response);
    }

    /**
     * Baixa o .jrxml registrado no servidor.
     *
     * A resposta é o XML cru (`application/xml`), não o envelope JSON do resto
     * da API — use `->body()` para o conteúdo. Útil para comparar o template
     * publicado com o local antes de reenviar.
     *
     * @throws ReportNotFoundException
     *                                 quando o template não existe ou o arquivo sumiu do storage
     */
    public function downloadJrxml(string $id): Response
    {
        return $this->http->download('/api/v1/reports/'.rawurlencode($id).'/jrxml');
    }

    public function delete(string $id): void
    {
        $this->http->delete('/api/v1/reports/'.rawurlencode($id));
    }

    public function deleteByName(string $name, string $theme = 'default'): void
    {
        $this->http->delete('/api/v1/reports/name/'.rawurlencode($name), ['theme' => $theme]);
    }

    /**
     * Gerações mais recentes do template, da mais nova para a mais antiga.
     *
     * Aceita `X-API-KEY` a partir do servidor 1.1 (antes, só o dashboard, e a
     * chamada dava 401) e não desconta da cota. Uma página só: `$perPage` vai
     * até 100.
     *
     * @return Collection<int, GenerationResource>
     */
    public function generations(string $id, int $perPage = 20): Collection
    {
        $response = $this->http->get('/api/v1/reports/'.rawurlencode($id).'/generations', [
            'per_page' => max(1, min($perPage, self::PER_PAGE)),
        ]);

        return collect($this->items($response))
            ->map(fn (array $item) => new GenerationResource($item, $this->http));
    }

    /**
     * Ver `generations()`. Sem `$theme`, o servidor usa o tema `default`.
     *
     * @return Collection<int, GenerationResource>
     */
    public function generationsByName(string $name, ?string $theme = null, int $perPage = 20): Collection
    {
        $response = $this->http->get('/api/v1/reports/name/'.rawurlencode($name).'/generations', array_filter([
            'theme' => $theme,
            'per_page' => max(1, min($perPage, self::PER_PAGE)),
        ], fn ($value) => $value !== null));

        return collect($this->items($response))
            ->map(fn (array $item) => new GenerationResource($item, $this->http));
    }
}
