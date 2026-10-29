<?php

namespace RiseTechApps\FusionReport\Resources;

use Illuminate\Http\Client\Response;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Http\FusionReportHttp;

class FileResource implements \JsonSerializable
{
    public function __construct(
        private readonly array $data,
        private readonly FusionReportHttp $http,
    ) {}

    /** @throws FusionReportException quando a resposta veio sem `id` */
    public function id(): string
    {
        $id = $this->data['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw FusionReportException::missingId('file', $this->data);
        }

        return $id;
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
            ?? FusionReportHttp::BASE_URL.'/api/v1/files/'.rawurlencode($this->id()).'/download';
    }

    public function expiresAt(): ?string
    {
        return $this->data['expires_at'] ?? null;
    }

    /** Tamanho em bytes; null quando o servidor não informa. */
    public function size(): ?int
    {
        $size = $this->data['size'] ?? null;

        return is_numeric($size) ? (int) $size : null;
    }

    public function download(): Response
    {
        return $this->http->download('/api/v1/files/'.rawurlencode($this->id()).'/download');
    }

    /** Re-exports a .frp file to the given format. Returns a new GenerationResource. */
    public function export(string $format): GenerationResource
    {
        $response = $this->http->post('/api/v1/files/'.rawurlencode($this->id()).'/export', ['format' => $format]);

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
