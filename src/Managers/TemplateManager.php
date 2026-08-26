<?php

namespace RiseTechApps\FusionReport\Managers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use RiseTechApps\FusionReport\Resources\TemplateResource;

class TemplateManager
{
    public function __construct(private readonly FusionReportHttp $http) {}

    /**
     * Lista todos os templates registrados, percorrendo todas as páginas.
     *
     * O servidor responde `data.reports` com o paginator serializado
     * (`{current_page, data: [...], last_page, ...}`), então os itens ficam um
     * nível abaixo. Sem paginar, o sync trataria templates das páginas
     * seguintes como não registrados e os reenviaria.
     *
     * @return Collection<int, TemplateResource>
     */
    public function list(): Collection
    {
        $items = collect();
        $page = 1;

        do {
            $response = $this->http->get('/api/v1/reports', [
                'page'     => $page,
                'per_page' => 100,
            ]);

            $paginator = $response['reports'] ?? $response;

            $items = $items->concat($this->items($paginator));

            $lastPage = (int) ($paginator['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage);

        return $items->map(fn(array $item) => new TemplateResource($item));
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

    public function find(string $id): TemplateResource
    {
        $response = $this->http->get("/api/v1/reports/{$id}");

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

    public function update(string $id, string $filePath, ?string $name = null, ?string $theme = null, ?string $description = null, ?string $resourcesZipPath = null): TemplateResource
    {
        $fields = array_filter(
            ['name' => $name, 'theme' => $theme, 'description' => $description],
            fn($v) => $v !== null,
        );

        $response = $this->http->postMultipart(
            "/api/v1/reports/{$id}",
            $fields,
            $filePath,
            basename($filePath),
            $resourcesZipPath,
        );

        return new TemplateResource($response);
    }

    public function updateByName(string $name, string $filePath, ?string $theme = null, ?string $description = null, ?string $resourcesZipPath = null): TemplateResource
    {
        $fields = array_filter(
            ['theme' => $theme, 'description' => $description],
            fn($v) => $v !== null,
        );

        $response = $this->http->postMultipart(
            "/api/v1/reports/name/{$name}",
            $fields,
            $filePath,
            basename($filePath),
            $resourcesZipPath,
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
     * @throws \RiseTechApps\FusionReport\Exceptions\ReportNotFoundException
     *         quando o template não existe ou o arquivo sumiu do storage
     */
    public function downloadJrxml(string $id): Response
    {
        return $this->http->download("/api/v1/reports/{$id}/jrxml");
    }

    public function delete(string $id): void
    {
        $this->http->delete("/api/v1/reports/{$id}");
    }

    public function deleteByName(string $name, string $theme = 'default'): void
    {
        $this->http->delete("/api/v1/reports/name/{$name}", ['theme' => $theme]);
    }

    /**
     * ATENÇÃO: rota restrita ao dashboard (Sanctum). Autenticado por `X-API-KEY`
     * o servidor responde 401, convertido em `FusionReportException`. Para
     * histórico use a tabela local `fusion_report_generations`.
     *
     * @return Collection<int, GenerationResource>
     */
    public function generations(string $id): Collection
    {
        $response = $this->http->get("/api/v1/reports/{$id}/generations");

        return collect($this->items($response))
            ->map(fn(array $item) => new GenerationResource($item, $this->http));
    }

    /**
     * ATENÇÃO: rota restrita ao dashboard (Sanctum). Autenticado por `X-API-KEY`
     * o servidor responde 401, convertido em `FusionReportException`. Para
     * histórico use a tabela local `fusion_report_generations`.
     *
     * @return Collection<int, GenerationResource>
     */
    public function generationsByName(string $name): Collection
    {
        $response = $this->http->get("/api/v1/reports/name/{$name}/generations");

        return collect($this->items($response))
            ->map(fn(array $item) => new GenerationResource($item, $this->http));
    }
}
