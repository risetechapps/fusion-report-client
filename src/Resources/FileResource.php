<?php

namespace RiseTechApps\FusionReport\Resources;

use Illuminate\Http\Client\Response;
use RiseTechApps\FusionReport\Http\FusionReportHttp;

class FileResource implements \JsonSerializable
{
    public function __construct(
        private readonly array $data,
        private readonly FusionReportHttp $http,
    ) {}

    public function id(): string
    {
        return $this->data['id'];
    }

    public function filename(): string
    {
        return $this->data['filename'] ?? '';
    }

    public function format(): string
    {
        return $this->data['format'] ?? '';
    }

    public function url(): string
    {
        return $this->data['download_url']
            ?? FusionReportHttp::BASE_URL . '/api/v1/files/' . $this->id() . '/download';
    }

    public function expiresAt(): ?string
    {
        return $this->data['expires_at'] ?? null;
    }

    public function createdAt(): ?string
    {
        return $this->data['created_at'] ?? null;
    }

    public function updatedAt(): ?string
    {
        return $this->data['updated_at'] ?? null;
    }

    public function download(): Response
    {
        return $this->http->download("/api/v1/files/{$this->id()}/download");
    }

    /** Re-exports a .frp file to the given format. Returns a new GenerationResource. */
    public function export(string $format): GenerationResource
    {
        $response = $this->http->post("/api/v1/files/{$this->id()}/export", ['format' => $format]);

        return new GenerationResource($response, $this->http);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
