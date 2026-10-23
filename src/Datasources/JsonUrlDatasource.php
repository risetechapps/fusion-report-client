<?php

namespace RiseTechApps\FusionReport\Datasources;

use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;

class JsonUrlDatasource implements Datasource
{
    private function __construct(
        private readonly string $url,
        private readonly string $method = 'get',
        private readonly array $headers = [],
    ) {}

    public static function get(string $url, array $headers = []): static
    {
        return new static($url, 'get', static::normalizeHeaders($headers));
    }

    public static function post(string $url, array $headers = []): static
    {
        return new static($url, 'post', static::normalizeHeaders($headers));
    }

    public function type(): string
    {
        return 'jsonUrl';
    }

    public function config(): array
    {
        return array_filter([
            'url'     => $this->url,
            'method'  => $this->method,
            'headers' => $this->headers ?: null,
        ]);
    }

    private static function normalizeHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $key => $value) {
            $result[] = is_int($key) ? $value : "{$key}={$value}";
        }
        return $result;
    }
}
