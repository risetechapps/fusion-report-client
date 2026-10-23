<?php

namespace RiseTechApps\FusionReport\Datasources;

use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;

class InlineJsonDatasource implements Datasource
{
    private function __construct(private readonly string $json) {}

    public static function from(array $data): static
    {
        return new static(json_encode($data));
    }

    public static function fromJson(string $json): static
    {
        return new static($json);
    }

    public function type(): string
    {
        return 'inline_json';
    }

    public function config(): array
    {
        return ['data_content' => $this->json];
    }
}
