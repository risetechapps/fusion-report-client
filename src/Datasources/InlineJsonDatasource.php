<?php

namespace RiseTechApps\FusionReport\Datasources;

use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;

class InlineJsonDatasource implements Datasource
{
    private function __construct(private readonly string $json) {}

    /**
     * @throws \JsonException quando os dados não serializam (ex.: UTF-8
     *                        inválido) — antes o `false` do json_encode virava `''` e o
     *                        relatório saía em branco sem erro
     */
    public static function from(array $data): static
    {
        return new static(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
