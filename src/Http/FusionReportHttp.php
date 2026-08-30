<?php

namespace RiseTechApps\FusionReport\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RiseTechApps\FusionReport\Exceptions\AuthenticationException;
use RiseTechApps\FusionReport\Exceptions\AuthorizationException;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Exceptions\RateLimitException;
use RiseTechApps\FusionReport\Exceptions\ReportNotFoundException;

class FusionReportHttp
{
    public const BASE_URL = 'https://fusionreport.app.br';

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
        return Http::baseUrl(self::BASE_URL)
            ->timeout(60)
            ->acceptJson()
            ->withHeader('X-API-KEY', $this->apiKey());
    }

    /**
     * Chave enviada em `X-API-KEY`.
     *
     * Lida a cada requisição, não no construtor: o container resolve este
     * serviço como singleton, então a chave capturada na primeira resolução
     * valia para o processo inteiro — um container montado antes de o config
     * estar resolvido mandava toda geração sem o header, e um contexto que
     * troca a chave em runtime (bootstrapper de tenant, worker de fila que
     * muda de contexto, teste que sobrescreve o config) continuava usando a
     * chave anterior.
     *
     * O header também deixou de ser opcional: sem chave a requisição saía
     * anônima e o erro só aparecia como 401 genérico do servidor, longe da
     * causa real.
     *
     * @throws AuthenticationException quando não há chave configurada
     */
    private function apiKey(): string
    {
        $key = config('fusion-report.api_key') ?? ($this->config['api_key'] ?? null);

        if (! is_string($key) || $key === '') {
            throw new AuthenticationException(
                'Missing Fusion Report api_key. Set FUSION_REPORT_API_KEY (config `fusion-report.api_key`) '
                . 'before generating reports — without it the request is sent with no X-API-KEY header.'
            );
        }

        return $key;
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->status() === 422) {
            $errors = $response->json('errors') ?? [];
            $message = $this->messageFrom($response) ?? 'Validation failed.';

            if ($errors) {
                $details = collect($errors)->map(fn($msgs) => implode(' ', $msgs))->implode(' ');
                $message .= ' ' . $details;
            }

            throw new FusionReportException($message);
        }

        if ($response->status() === 401) {
            throw new AuthenticationException(
                $this->messageFrom($response) ?? 'Unauthenticated. Check the configured api_key.'
            );
        }

        if ($response->status() === 403) {
            throw new AuthorizationException(
                $this->messageFrom($response) ?? 'This resource belongs to another account.'
            );
        }

        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');

            throw new RateLimitException(
                $this->messageFrom($response) ?? 'Request limit reached for the current plan.',
                is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        if ($response->status() === 404) {
            throw new ReportNotFoundException(
                $this->messageFrom($response) ?? 'Resource not found.'
            );
        }

        if ($response->status() === 410) {
            throw new ReportNotFoundException(
                $this->messageFrom($response) ?? 'Resource not found or expired.'
            );
        }

        if ($response->failed()) {
            throw new FusionReportException(
                $this->messageFrom($response) ?? "Fusion Report Server error [{$response->status()}]."
            );
        }
    }

    /**
     * Mensagem de erro da resposta.
     *
     * O envelope padrão do servidor usa `message`, mas o middleware de cota
     * responde `{"error": "..."}` — sem ler as duas, o motivo do 429 se perdia.
     */
    private function messageFrom(Response $response): ?string
    {
        $message = $response->json('message') ?? $response->json('error');

        return is_string($message) && $message !== '' ? $message : null;
    }
}
