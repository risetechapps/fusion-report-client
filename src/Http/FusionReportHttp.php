<?php

namespace RiseTechApps\FusionReport\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Exceptions\ReportNotFoundException;

class FusionReportHttp
{
    public const BASE_URL = 'https://report.risetech.dev.br';

    public function __construct(private readonly array $config) {}

    public function post(string $endpoint, array $data): array
    {
        $response = $this->client()->post($endpoint, $data);

        $this->assertSuccessful($response);

        return $response->json('data') ?? [];
    }

    public function get(string $endpoint, array $query = []): array
    {
        $response = $this->client()->get($endpoint, $query);

        $this->assertSuccessful($response);

        return $response->json('data') ?? [];
    }

    public function delete(string $endpoint, array $query = []): void
    {
        $response = $this->client()->delete($endpoint, $query);

        $this->assertSuccessful($response);
    }

    public function postMultipart(string $endpoint, array $fields, string $filePath, string $fileName, ?string $resourcesPath = null): array
    {
        $request = $this->baseClient()
            ->attach('file', file_get_contents($filePath), $fileName);

        if ($resourcesPath !== null) {
            $request = $request->attach('resources', file_get_contents($resourcesPath), basename($resourcesPath));
        }

        $response = $request->post($endpoint, $fields);

        $this->assertSuccessful($response);

        return $response->json('data') ?? [];
    }

    public function download(string $endpoint): Response
    {
        $response = $this->baseClient()->withOptions(['stream' => true])->get($endpoint);

        $this->assertSuccessful($response);

        return $response;
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return $this->baseClient()->asJson();
    }

    private function baseClient(): \Illuminate\Http\Client\PendingRequest
    {
        $pending = Http::baseUrl(self::BASE_URL)
            ->timeout(60)
            ->acceptJson();

        if (! empty($this->config['api_key'])) {
            $pending = $pending->withHeader('X-API-KEY', $this->config['api_key']);
        }

        return $pending;
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->status() === 422) {
            $errors = $response->json('errors') ?? [];
            $message = $response->json('message') ?? 'Validation failed.';

            if ($errors) {
                $details = collect($errors)->map(fn($msgs) => implode(' ', $msgs))->implode(' ');
                $message .= ' ' . $details;
            }

            throw new FusionReportException($message);
        }

        if ($response->status() === 404) {
            throw new ReportNotFoundException(
                $response->json('message') ?? 'Resource not found.'
            );
        }

        if ($response->status() === 410) {
            throw new ReportNotFoundException(
                $response->json('message') ?? 'Resource not found or expired.'
            );
        }

        if ($response->failed()) {
            throw new FusionReportException(
                $response->json('message') ?? "Fusion Report Server error [{$response->status()}]."
            );
        }
    }
}
