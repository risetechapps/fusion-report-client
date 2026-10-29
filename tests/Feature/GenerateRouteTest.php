<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Http;
use RiseTechApps\FusionReport\Facades\FusionReport;

beforeEach(function () {
    FusionReport::routes(['middleware' => []]);

    Http::fake(['*' => Http::response(['data' => ['id' => 'g-1', 'status' => 'pending', 'mode' => 'async']], 200)]);

    $this->user = (new User)->forceFill(['id' => 1, 'email' => 'dono@exemplo.com', 'cliente_id' => 7]);
    $this->actingAs($this->user);
});

it('responde 404 para relatório não registrado, sem chamar o servidor', function () {
    $this->postJson('/reports/generate', ['name' => 'qualquer'])->assertNotFound();

    Http::assertNothingSent();
});

it('nega por padrão quando a definition não libera a rota', function () {
    $this->postJson('/reports/generate', ['name' => 'relatorio'])->assertForbidden();

    Http::assertNothingSent();
});

it('libera quando authorize aceita os parâmetros', function () {
    $this->postJson('/reports/generate', ['name' => 'por_cliente', 'params' => ['cliente_id' => 7]])
        ->assertOk()
        ->assertJsonPath('data.generation_id', 'g-1');

    Http::assertSentCount(1);
});

it('nega quando o usuário troca o cliente_id por outro', function () {
    $this->postJson('/reports/generate', ['name' => 'por_cliente', 'params' => ['cliente_id' => 8]])
        ->assertForbidden();

    Http::assertNothingSent();
});

it('só envia por e-mail para o endereço do próprio usuário', function () {
    $this->postJson('/reports/generate', [
        'name' => 'por_cliente', 'params' => ['cliente_id' => 7], 'email' => 'fora@exemplo.com',
    ])->assertForbidden();

    Http::assertNothingSent();

    $this->postJson('/reports/generate', [
        'name' => 'por_cliente', 'params' => ['cliente_id' => 7], 'email' => 'DONO@exemplo.com',
    ])->assertOk();
});

it('responde 422 quando format não é string nem lista de strings', function (mixed $format) {
    $this->postJson('/reports/generate', [
        'name' => 'por_cliente', 'params' => ['cliente_id' => 7], 'format' => $format,
    ])->assertUnprocessable()->assertJsonValidationErrors('format');

    Http::assertNothingSent();
})->with([
    'número' => 123,
    'lista aninhada' => [[['pdf']]],
    'mapa' => [['a' => 'pdf']],
]);

it('aceita format como string ou lista', function (mixed $format) {
    $this->postJson('/reports/generate', [
        'name' => 'por_cliente', 'params' => ['cliente_id' => 7], 'format' => $format,
    ])->assertOk();
})->with([
    'string' => 'pdf,xlsx',
    'lista' => [['pdf', 'xlsx']],
]);
