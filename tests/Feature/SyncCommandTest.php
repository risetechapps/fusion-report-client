<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RiseTechApps\FusionReport\Tests\Fixtures\DuplicateThemeReport;
use RiseTechApps\FusionReport\Tests\Fixtures\EmptyThemeReport;
use RiseTechApps\FusionReport\Tests\Fixtures\SameNameReport;
use RiseTechApps\FusionReport\Tests\Fixtures\SyncReport;
use RiseTechApps\FusionReport\Tests\Fixtures\TestReport;

beforeEach(function () {
    SyncReport::$jrxml = tempnam(sys_get_temp_dir(), 'frc').'.jrxml';
    file_put_contents(SyncReport::$jrxml, '<jasperReport/>');

    config(['fusion-report.reports' => ['vendas' => SyncReport::class]]);

    // Servidor: os dois temas declarados + um template que saiu do config.
    // Lido da propriedade a cada requisição, para o teste poder trocar a lista.
    Http::fake(function (Request $r) {
        if ($r->method() === 'GET') {
            return Http::response(['data' => [
                'reports' => ['data' => $this->templates, 'last_page' => 1],
                'template_limit' => $this->limit,
                'template_count' => count($this->templates),
            ]]);
        }

        return Http::response(['data' => ['id' => 'novo']]);
    });

    $this->templates = [
        ['id' => 't-1', 'name' => 'vendas', 'theme' => 'azul'],
        ['id' => 't-2', 'name' => 'vendas', 'theme' => 'verde'],
        ['id' => 't-3', 'name' => 'estoque', 'theme' => 'default'],
    ];

    $this->limit = null;

    $this->deletes = fn () => collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'DELETE')
        ->map(fn ($pair) => basename(parse_url($pair[0]->url(), PHP_URL_PATH)))
        ->values()
        ->all();
});

afterEach(fn () => @unlink(SyncReport::$jrxml));

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

it('avisa quando os templates novos passam do limite do plano', function () {
    // Servidor só com 1 template, limite 2: os 2 temas novos estouram em 1.
    $this->templates = [['id' => 't-3', 'name' => 'estoque', 'theme' => 'default']];
    $this->limit = 2;

    $this->artisan('fusion-report:sync', ['--no-interaction' => true])
        ->expectsOutputToContain('Plan template limit: 1 of 2 used, 2 new to register — 1 will be refused by the server.')
        ->assertSuccessful();
});

it('não avisa sobre o limite quando cabe ou quando não há limite', function (?int $limit) {
    $this->templates = [];
    $this->limit = $limit;

    $this->artisan('fusion-report:sync', ['--no-interaction' => true])
        ->doesntExpectOutputToContain('Plan template limit')
        ->assertSuccessful();
})->with(['cabe' => [5], 'sem limite' => [null]]);

it('atualiza no --force pelo tema na query string', function () {
    $this->artisan('fusion-report:sync', ['--force' => true, '--all' => true])->assertSuccessful();

    $updates = collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => $r->method() === 'POST')
        ->map(fn (Request $r) => [parse_url($r->url(), PHP_URL_PATH), parse_url($r->url(), PHP_URL_QUERY)])
        ->values()
        ->all();

    expect($updates)->toBe([
        ['/api/v1/reports/name/vendas', 'theme=azul'],
        ['/api/v1/reports/name/vendas', 'theme=verde'],
    ]);
});

// ── validação do config ──────────────────────────────────────────────────

it('não envia tema declarado duas vezes e segue com o resto', function () {
    config(['fusion-report.reports' => [
        'vendas' => SyncReport::class,
        'estoque' => DuplicateThemeReport::class,
    ]]);

    $this->artisan('fusion-report:sync', ['--force' => true, '--all' => true, '--no-interaction' => true])
        ->expectsOutputToContain('Theme [azul] declared 2 times in '.DuplicateThemeReport::class)
        ->expectsOutputToContain('Registered: 0 | Updated: 2 | Ignored: 0 | Failures: 2')
        ->assertFailed();

    // Só os temas da SyncReport foram enviados; nada do `estoque`.
    $posts = collect(Http::recorded())
        ->filter(fn ($pair) => $pair[0]->method() === 'POST')
        ->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))
        ->all();

    expect($posts)->not->toContain('/api/v1/reports/name/estoque')
        ->and(array_values($posts))->toBe(['/api/v1/reports/name/vendas', '/api/v1/reports/name/vendas']);
});

it('não envia nada de definitions com o mesmo name()', function () {
    config(['fusion-report.reports' => [
        'vendas' => SyncReport::class,
        'vendas_novo' => SameNameReport::class,
    ]]);
    $this->templates = [];

    $this->artisan('fusion-report:sync', ['--no-interaction' => true])
        ->expectsOutputToContain('Report [vendas] declared in 2 config keys: vendas ('.SyncReport::class.'), vendas_novo ('.SameNameReport::class.')')
        ->expectsOutputToContain('Registered: 0 | Updated: 0 | Ignored: 0 | Failures: 3')
        ->assertFailed();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('aponta as chaves do config quando a mesma classe é registrada duas vezes', function () {
    config(['fusion-report.reports' => [
        'vendas' => SyncReport::class,
        'vendas_old' => SyncReport::class,
    ]]);
    $this->templates = [];

    // Antes a mensagem culpava o themes() ("Theme [x] declared 2 times").
    $this->artisan('fusion-report:sync', ['--no-interaction' => true])
        ->expectsOutputToContain('Report [vendas] declared in 2 config keys: vendas ('.SyncReport::class.'), vendas_old ('.SyncReport::class.')')
        ->doesntExpectOutputToContain('declared 2 times')
        ->expectsOutputToContain('Registered: 0 | Updated: 0 | Ignored: 0 | Failures: 4')
        ->assertFailed();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('não envia tema sem nome', function () {
    config(['fusion-report.reports' => ['compras' => EmptyThemeReport::class]]);

    $this->artisan('fusion-report:sync', ['--no-interaction' => true])
        ->expectsOutputToContain('Theme name is empty in '.EmptyThemeReport::class)
        ->assertFailed();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('não remove no --prune o que está declarado, mesmo inválido', function () {
    // O config ainda declara `estoque|azul`: erro de config não é motivo para apagar.
    config(['fusion-report.reports' => [
        'vendas' => SyncReport::class,
        'estoque' => DuplicateThemeReport::class,
    ]]);
    $this->templates[] = ['id' => 't-9', 'name' => 'estoque', 'theme' => 'azul'];

    $this->artisan('fusion-report:sync', ['--prune' => true, '--no-interaction' => true])
        ->assertFailed();

    expect(($this->deletes)())->toBe(['t-3']);
});
