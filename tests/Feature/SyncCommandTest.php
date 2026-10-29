<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RiseTechApps\FusionReport\Tests\Fixtures\SyncReport;
use RiseTechApps\FusionReport\Tests\Fixtures\TestReport;

beforeEach(function () {
    SyncReport::$jrxml = tempnam(sys_get_temp_dir(), 'frc') . '.jrxml';
    file_put_contents(SyncReport::$jrxml, '<jasperReport/>');

    config(['fusion-report.reports' => ['vendas' => SyncReport::class]]);

    // Servidor: os dois temas declarados + um template que saiu do config.
    // Lido da propriedade a cada requisição, para o teste poder trocar a lista.
    Http::fake(function (Request $r) {
        if ($r->method() === 'GET') {
            return Http::response(['data' => ['reports' => ['data' => $this->templates, 'last_page' => 1]]]);
        }

        return Http::response(['data' => ['id' => 'novo']]);
    });

    $this->templates = [
        ['id' => 't-1', 'name' => 'vendas', 'theme' => 'azul'],
        ['id' => 't-2', 'name' => 'vendas', 'theme' => 'verde'],
        ['id' => 't-3', 'name' => 'estoque', 'theme' => 'default'],
    ];

    $this->deletes = fn() => collect(Http::recorded())
        ->filter(fn($pair) => $pair[0]->method() === 'DELETE')
        ->map(fn($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)))
        ->values()
        ->all();
});

afterEach(fn() => @unlink(SyncReport::$jrxml));

it('não remove nada sem --prune', function () {
    $this->artisan('fusion-report:sync')->assertSuccessful();

    expect(($this->deletes)())->toBe([]);
});

it('remove só o que saiu do config, sem perguntar fora do terminal', function () {
    $this->artisan('fusion-report:sync', ['--prune' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Removed: 1 | Failures: 0')
        ->assertSuccessful();

    expect(($this->deletes)())->toBe(['t-3']);
});

it('pede confirmação no terminal e não remove quando negada', function () {
    $this->artisan('fusion-report:sync', ['--prune' => true])
        ->expectsConfirmation('Remove these 1 template(s) from the server? This cannot be undone.', 'no')
        ->expectsOutputToContain('Prune cancelled')
        ->assertSuccessful();

    expect(($this->deletes)())->toBe([]);
});

it('remove quando confirmado no terminal', function () {
    $this->artisan('fusion-report:sync', ['--prune' => true])
        ->expectsConfirmation('Remove these 1 template(s) from the server? This cannot be undone.', 'yes')
        ->assertSuccessful();

    expect(($this->deletes)())->toBe(['t-3']);
});

it('não remove temas de definition com themes() vazio', function () {
    // Erro de config: o nome continua sendo do app.
    config(['fusion-report.reports' => ['vendas' => SyncReport::class, 'relatorio' => TestReport::class]]);
    $this->templates = [
        ['id' => 't-1', 'name' => 'vendas', 'theme' => 'azul'],
        ['id' => 't-2', 'name' => 'vendas', 'theme' => 'verde'],
        ['id' => 't-4', 'name' => 'relatorio', 'theme' => 'default'],
    ];

    $this->artisan('fusion-report:sync', ['--prune' => true, '--no-interaction' => true])
        ->expectsOutputToContain('no template on the server outside the config')
        ->assertFailed(); // falha do themes() vazio no sync

    expect(($this->deletes)())->toBe([]);
});

it('compara com tudo que o config declara, mesmo no --force sem seleção', function () {
    $this->artisan('fusion-report:sync', ['--force' => true, '--prune' => true])
        ->expectsChoice('Which templates to update?', [], ['vendas|azul' => 'vendas | azul', 'vendas|verde' => 'vendas | verde'])
        ->expectsConfirmation('Remove these 1 template(s) from the server? This cannot be undone.', 'yes')
        ->assertSuccessful();

    expect(($this->deletes)())->toBe(['t-3']);
});
