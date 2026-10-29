<?php

namespace RiseTechApps\FusionReport\Datasources;

use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;

class InlineCsvDatasource implements Datasource
{
    private function __construct(
        private readonly string $csv,
        private readonly bool $firstRow = true,
        private readonly string $delimiter = ',',
    ) {}

    /** @throws FusionReportException quando o delimitador não tem 1 caractere */
    public static function from(string $csv, bool $firstRow = true, string $delimiter = ','): static
    {
        static::assertDelimiter($delimiter);

        return new static($csv, $firstRow, $delimiter);
    }

    /**
     * Monta o CSV a partir de linhas, com aspas e escape do RFC 4180.
     *
     * O implode direto quebrava com valor contendo o separador, aspas ou
     * quebra de linha: `"Silva, Ana"` virava duas colunas e `"a\nb"` dois
     * registros, desalinhando o relatório sem erro nenhum.
     *
     * @throws FusionReportException quando um valor não tem representação textual
     *                               ou o separador não tem 1 caractere
     */
    public static function fromRows(array $rows, string $separator = ','): static
    {
        static::assertDelimiter($separator);

        $handle = fopen('php://temp', 'r+');

        try {
            foreach ($rows as $row) {
                // escape '' desliga o escape não padrão do PHP (aspas viram "")
                // e evita o aviso de depreciação do PHP 8.4.
                fputcsv($handle, array_map(static::stringify(...), array_values($row)), $separator, '"', '', "\n");
            }

            rewind($handle);
            $csv = rtrim(stream_get_contents($handle), "\n");
        } finally {
            fclose($handle);
        }

        return new static($csv, false, $separator);
    }

    public function type(): string
    {
        return 'inline_csv';
    }

    public function config(): array
    {
        return [
            'data_content' => $this->csv,
            'csv_first_row' => $this->firstRow,
            'csv_field_delimiter' => $this->delimiter,
        ];
    }

    /**
     * O servidor aceita `csv_field_delimiter` de 1 caractere, e o `fputcsv`, de
     * 1 byte. Sem checar aqui, `';;'` só voltava 422 na geração e `''`
     * estourava `ValueError` do PHP sem dizer a causa.
     */
    private static function assertDelimiter(string $delimiter): void
    {
        if (strlen($delimiter) !== 1) {
            throw new FusionReportException(sprintf(
                'CSV delimiter must be a single character, [%s] given.', $delimiter,
            ));
        }
    }

    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \Stringable => (string) $value,
            default => throw new FusionReportException(
                sprintf('CSV value of type %s cannot be serialized.', get_debug_type($value)),
            ),
        };
    }
}
