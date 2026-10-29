<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Facades\FusionReport;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;
use RiseTechApps\FusionReport\Tests\Fixtures\Cliente;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['fusion-report.log_generations' => true]);

    Http::fake(['*' => Http::response(['data' => ['id' => 'g-1', 'status' => 'pending']])]);

    $this->cliente = (new Cliente)->forceFill(['id' => (string) Str::uuid7()]);
});

afterEach(function () {
    // Estado estático do Eloquent: não vazar para os outros testes.
    Relation::morphMap([], merge: false);
    Relation::requireMorphMap(false);
});

it('grava o FQCN como loggable_type quando o app não tem morph map', function () {
    FusionReport::report('x')->for($this->cliente)->generateAsync();

    expect(FusionReportGeneration::sole()->loggable_type)->toBe(Cliente::class)
        ->and($this->cliente->fusionReportGenerations()->pluck('generation_uuid')->all())->toBe(['g-1']);
});

it('grava o alias do morph map como loggable_type', function () {
    // Antes gravava o FQCN, e a relação — que busca pelo alias — voltava vazia.
    Relation::enforceMorphMap(['cliente' => Cliente::class]);

    FusionReport::report('x')->for($this->cliente)->generateAsync();

    expect(FusionReportGeneration::sole()->loggable_type)->toBe('cliente')
        ->and($this->cliente->fusionReportGenerations()->pluck('generation_uuid')->all())->toBe(['g-1']);
});

it('deixa loggable vazio sem dono', function () {
    FusionReport::report('x')->generateAsync();

    expect(FusionReportGeneration::sole())
        ->loggable_type->toBeNull()
        ->loggable_id->toBeNull();
});

it('recusa dono sem chave UUID antes de chamar o servidor', function () {
    // Antes: o servidor gerava (e cobrava) e o insert falhava no Postgres.
    $user = (new User)->forceFill(['id' => 42]);

    expect(fn () => FusionReport::report('x')->for($user)->generate())
        ->toThrow(FusionReportException::class, 'Report owner ['.User::class.'] must have a UUID key');

    Http::assertNothingSent();
    expect(FusionReportGeneration::count())->toBe(0);
});

it('aceita dono sem chave UUID com log_generations desligado', function () {
    config(['fusion-report.log_generations' => false]);
    $user = (new User)->forceFill(['id' => 42]);

    FusionReport::report('x')->for($user)->generateAsync();

    Http::assertSentCount(1);
});
