<?php

namespace RiseTechApps\FusionReport\Managers;

use Illuminate\Support\Collection;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use RiseTechApps\FusionReport\Resources\TemplateResource;

class TemplateManager
{
    public function __construct(private readonly FusionReportHttp $http) {}

    /** @return Collection<int, TemplateResource> */
    public function list(): Collection
    {
        $response = $this->http->get('/api/v1/reports');

        $items = $response['reports'] ?? $response['data'] ?? $response;

        return collect($items)->map(fn(array $item) => new TemplateResource($item));
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

    public function delete(string $id): void
    {
        $this->http->delete("/api/v1/reports/{$id}");
    }

    public function deleteByName(string $name, string $theme = 'default'): void
    {
        $this->http->delete("/api/v1/reports/name/{$name}", ['theme' => $theme]);
    }

    /** @return Collection<int, GenerationResource> */
    public function generations(string $id): Collection
    {
        $response = $this->http->get("/api/v1/reports/{$id}/generations");

        $items = $response['data'] ?? $response;

        return collect($items)->map(fn(array $item) => new GenerationResource($item, $this->http));
    }

    /** @return Collection<int, GenerationResource> */
    public function generationsByName(string $name): Collection
    {
        $response = $this->http->get("/api/v1/reports/name/{$name}/generations");

        $items = $response['data'] ?? $response;

        return collect($items)->map(fn(array $item) => new GenerationResource($item, $this->http));
    }
}
