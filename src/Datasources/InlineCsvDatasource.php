<?php

namespace RiseTechApps\FusionReport\Datasources;

use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;

class InlineCsvDatasource implements Datasource
{
    private function __construct(
        private readonly string $csv,
        private readonly bool $firstRow = true,
        private readonly string $delimiter = ',',
    ) {}

    public static function from(string $csv, bool $firstRow = true, string $delimiter = ','): static
    {
        return new static($csv, $firstRow, $delimiter);
    }

    public static function fromRows(array $rows, string $separator = ','): static
    {
        $lines = array_map(
            fn(array $row) => implode($separator, array_map('strval', array_values($row))),
            $rows
        );

        return new static(implode("\n", $lines), false, $separator);
    }

    public function type(): string
    {
        return 'inline_csv';
    }

    public function config(): array
    {
        return [
            'data_content'        => $this->csv,
            'csv_first_row'       => $this->firstRow,
            'csv_field_delimiter' => $this->delimiter,
        ];
    }
}
