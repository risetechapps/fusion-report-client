<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Facades\FusionReport;
use RiseTechApps\FusionReport\Tests\Fixtures\ParamsReport;
use RiseTechApps\FusionReport\Tests\Fixtures\Prioridade;
use RiseTechApps\FusionReport\Tests\Fixtures\Situacao;

beforeEach(function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'g-1', 'status' => 'pending']])]);

    // Payload da única requisição enviada.
    $this->sent = function (): array {
        $recorded = Http::recorded();

        expect($recorded)->toHaveCount(1);

        return $recorded[0][0]->data();
    };
});

// ── params ───────────────────────────────────────────────────────────────

it('serializa cada tipo de param para KEY=valor', function (mixed $value, string $expected) {
    FusionReport::report('x')->params(['P' => $value])->generate();

    expect(($this->sent)()['params'])->toBe(["P={$expected}"]);
})->with([
    'null' => [null, ''],
    'true' => [true, 'true'],
    'false' => [false, 'false'], // antes virava `P=`, igual a string vazia
    'int' => [2026, '2026'],
    'float' => [1.5, '1.5'],
    'string' => ['Acme', 'Acme'],
    'enum' => [Situacao::Ativo, 'ATIVO'],
    'data' => [Carbon::parse('2026-10-28 10:00:00', 'UTC'), '2026-10-28'], // yyyy-MM-dd do java.util.Date
    'Stringable' => [str('Acme'), 'Acme'],
    'array' => [[1, 2, 'três'], '[1,2,"três"]'], // antes: "Array to string conversion"
]);

it('recusa param sem representação textual', function (mixed $value, string $type) {
    expect(fn () => FusionReport::report('x')->params(['P' => $value])->generate())
        ->toThrow(FusionReportException::class, "Report param [P] of type {$type} cannot be serialized.");
})->with([
    'objeto' => [new stdClass, 'stdClass'],
    'enum sem valor' => [Prioridade::Alta, Prioridade::class],
]);

it('não envia a requisição quando um param não serializa', function () {
    try {
        FusionReport::report('x')->params(['OBJ' => new stdClass])->generate();
    } catch (FusionReportException $e) {
        expect($e->getMessage())->toBe('Report param [OBJ] of type stdClass cannot be serialized.');
    }

    Http::assertNothingSent();
});

it('acumula params entre chamadas, a última vencendo', function () {
    FusionReport::report('x')
        ->params(['A' => 1, 'B' => 1])
        ->params(['B' => 2])
        ->generate();

    expect(($this->sent)()['params'])->toBe(['A=1', 'B=2']);
});

it('aplica a precedência config < defaultParams() < chamada', function () {
    config(['fusion-report.defaults.params' => ['EMPRESA' => 'Config', 'MOEDA' => 'BRL']]);
    FusionReport::register('com_params', ParamsReport::class);

    FusionReport::make('com_params', ['ANO' => 2026])->generate();

    expect(($this->sent)()['params'])->toEqualCanonicalizing([
        'EMPRESA=Definition', // definition vence o config
        'MOEDA=BRL',          // só o config define
        'ANO=2026',           // chamada vence a definition
    ]);
});

it('omite params do payload quando não há nenhum', function () {
    FusionReport::report('x')->generate();

    expect(($this->sent)())->not->toHaveKey('params');
});

// ── format ───────────────────────────────────────────────────────────────

it('normaliza o format informado', function (string|array $format, array $expected) {
    FusionReport::report('x')->format($format)->generate();

    expect(($this->sent)()['format'])->toBe($expected);
})->with([
    'string' => ['pdf', ['pdf']],
    'separado por vírgula' => ['PDF, xlsx', ['pdf', 'xlsx']],
    'array' => [[' Docx ', 'csv'], ['docx', 'csv']],
    'repetidos' => [['pdf', 'PDF', 'pdf'], ['pdf']],
    'itens vazios' => ['pdf,,', ['pdf']],
]);

it('acumula formatos entre chamadas, sem repetir', function () {
    // Por design não há como substituir: ->format() só adiciona.
    FusionReport::report('x')->format('pdf')->format(['xlsx', 'pdf'])->generate();

    expect(($this->sent)()['format'])->toBe(['pdf', 'xlsx']);
});

it('usa defaults.format do config quando não há ->format()', function () {
    config(['fusion-report.defaults.format' => 'PDF, xlsx']);

    FusionReport::report('x')->generate();

    expect(($this->sent)()['format'])->toBe(['pdf', 'xlsx']);
});

it('ignora defaults.format quando ->format() foi informado', function () {
    config(['fusion-report.defaults.format' => 'pdf']);

    FusionReport::report('x')->format('xlsx')->generate();

    expect(($this->sent)()['format'])->toBe(['xlsx']);
});

it('omite format do payload sem ->format() nem default', function () {
    // O servidor responde 422 — o cliente não inventa um formato.
    config(['fusion-report.defaults.format' => null]);

    FusionReport::report('x')->generate();

    expect(($this->sent)())->not->toHaveKey('format');
});

it('preserva chaves numéricas dos params', function () {
    // array_merge renumerava: `['2026' => 'x']` saía como `0=x`.
    config(['fusion-report.defaults.params' => [10 => 'config']]);

    FusionReport::report('x')
        ->params(['2026' => 'x', 10 => 'chamada'])
        ->params([7 => 'y'])
        ->generate();

    expect(($this->sent)()['params'])->toEqualCanonicalizing(['10=chamada', '2026=x', '7=y']);
});

it('preserva chaves numéricas na precedência de make()', function () {
    FusionReport::register('com_params', ParamsReport::class);

    FusionReport::make('com_params', [2026 => 'x'])->generate();

    expect(($this->sent)()['params'])->toContain('2026=x')->not->toContain('0=x');
});

it('preserva chaves numéricas nos webhookParams', function () {
    FusionReport::report('x')->webhookParams([42 => 'a'])->generateAsync('https://app.test/hook');

    expect(($this->sent)()['webhook_url'])->toBe('https://app.test/hook?42=a');
});

it('recusa item de format que não é string, sem enviar nada', function (array $format, string $type) {
    expect(fn () => FusionReport::report('x')->format($format)->generate())
        ->toThrow(FusionReportException::class, "Report format must be a string, {$type} given.");

    Http::assertNothingSent();
})->with([
    'array aninhado' => [[['pdf']], 'array'],
    'int' => [[1], 'int'],
    'null' => [['pdf', null], 'null'],
]);

// ── webhook ──────────────────────────────────────────────────────────────

it('manda o webhook do config também no síncrono, para o caso do 202', function () {
    config(['fusion-report.webhook.url' => 'https://app.test/hook']);

    FusionReport::report('x')->webhookParams(['t' => 1])->generate();

    expect(($this->sent)()['webhook_url'])->toBe('https://app.test/hook?t=1');
});

it('aceita webhook explícito no síncrono', function () {
    FusionReport::report('x')->generate(webhook: 'https://outro.test/hook');

    expect(($this->sent)()['webhook_url'])->toBe('https://outro.test/hook');
});

it('omite webhook_url no síncrono sem webhook configurado', function () {
    config(['fusion-report.webhook.url' => null]);

    FusionReport::report('x')->generate();

    expect(($this->sent)())->not->toHaveKey('webhook_url');
});

it('recusa defaults.format inválido no config', function () {
    config(['fusion-report.defaults.format' => [['pdf']]]);

    expect(fn () => FusionReport::report('x')->generate())
        ->toThrow(FusionReportException::class, 'Report format must be a string, array given.');

    Http::assertNothingSent();
});
