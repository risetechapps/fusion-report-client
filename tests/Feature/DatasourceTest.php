<?php

use RiseTechApps\FusionReport\Datasources\InlineCsvDatasource;
use RiseTechApps\FusionReport\Datasources\InlineJsonDatasource;
use RiseTechApps\FusionReport\Datasources\JsonUrlDatasource;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Tests\Fixtures\Situacao;

// ── InlineCsvDatasource ──────────────────────────────────────────────────

it('escapa separador, aspas e quebra de linha em fromRows()', function () {
    $csv = InlineCsvDatasource::fromRows([
        ['Silva, Ana', 'diz "oi"'],
        ['x', "linha\nnova"],
    ])->config()['data_content'];

    expect($csv)->toBe("\"Silva, Ana\",\"diz \"\"oi\"\"\"\nx,\"linha\nnova\"");

    // Lido de volta, mantém 2 linhas de 2 colunas.
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $csv);
    rewind($handle);
    $parsed = [];
    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $parsed[] = $row;
    }

    expect($parsed)->toBe([['Silva, Ana', 'diz "oi"'], ['x', "linha\nnova"]]);
});

it('respeita o separador informado em fromRows()', function () {
    $config = InlineCsvDatasource::fromRows([['a;b', 'c']], ';')->config();

    expect($config['data_content'])->toBe('"a;b";c')
        ->and($config['csv_field_delimiter'])->toBe(';')
        ->and($config['csv_first_row'])->toBeFalse();
});

it('serializa cada tipo de valor em fromRows()', function () {
    $csv = InlineCsvDatasource::fromRows([[null, true, false, 10, 1.5, Situacao::Ativo, str('s')]])
        ->config()['data_content'];

    expect($csv)->toBe(',true,false,10,1.5,ATIVO,s');
});

it('recusa valor sem representação textual em fromRows()', function () {
    InlineCsvDatasource::fromRows([[['aninhado']]]);
})->throws(FusionReportException::class, 'CSV value of type array cannot be serialized.');

it('recusa delimitador que não tem 1 caractere', function (string $method, string $delimiter) {
    $method === 'from'
        ? InlineCsvDatasource::from('a,b', delimiter: $delimiter)
        : InlineCsvDatasource::fromRows([['a', 'b']], $delimiter);
})->with([
    'from vazio' => ['from', ''],
    'from duplo' => ['from', ';;'],
    'fromRows vazio' => ['fromRows', ''],
    'fromRows duplo' => ['fromRows', ';;'],
    'multibyte' => ['from', '¦'],
])->throws(FusionReportException::class, 'CSV delimiter must be a single character');

it('gera CSV vazio sem linhas', function () {
    expect(InlineCsvDatasource::fromRows([])->config()['data_content'])->toBe('');
});

// ── JsonUrlDatasource ────────────────────────────────────────────────────

it('monta o config do jsonUrl em cada método', function (string $method) {
    $config = JsonUrlDatasource::{$method}('https://api.test/dados', ['Authorization' => 'Bearer abc=='])->config();

    expect($config)->toBe([
        'url' => 'https://api.test/dados',
        'method' => $method,
        'headers' => ['Authorization=Bearer abc=='],
    ]);
})->with(['get', 'post', 'put', 'delete']);

it('omite headers do jsonUrl quando não há nenhum', function () {
    expect(JsonUrlDatasource::get('https://api.test/dados')->config())->not->toHaveKey('headers');
});

// ── InlineJsonDatasource ─────────────────────────────────────────────────

it('serializa o JSON sem escapar unicode', function () {
    expect(InlineJsonDatasource::from(['nome' => 'João'])->config())
        ->toBe(['data_content' => '{"nome":"João"}']);
});

it('recusa dados que não serializam em vez de mandar vazio', function () {
    InlineJsonDatasource::from(['n' => "\xB1"]);
})->throws(JsonException::class);
