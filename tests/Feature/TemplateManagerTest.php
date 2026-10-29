<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Facades\FusionReport;

beforeEach(function () {
    $this->jrxml = tempnam(sys_get_temp_dir(), 'frc').'.jrxml';
    file_put_contents($this->jrxml, '<jasperReport/>');

    // Lido a cada requisição: um segundo Http::fake() não substituiria este.
    // Pode ser closure, para variar por requisição (paginação).
    $this->response = ['data' => ['id' => 't-1']];
    Http::fake(fn (Request $r) => Http::response(
        $this->response instanceof Closure ? ($this->response)($r) : $this->response,
    ));

    // Única requisição enviada: query string e nomes das partes do multipart.
    $this->sent = function (): array {
        $recorded = Http::recorded();

        expect($recorded)->toHaveCount(1);

        /** @var Request $request */
        $request = $recorded[0][0];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $parts = $request->isMultipart()
            ? collect($request->data())->mapWithKeys(fn ($part) => [$part['name'] => $part['contents']])
            : collect();

        return [
            'method' => $request->method(),
            'path' => parse_url($request->url(), PHP_URL_PATH),
            'query' => $query,
            'parts' => $parts,
            'body' => $request->isMultipart() ? null : $request->body(),
        ];
    };
});

afterEach(fn () => @unlink($this->jrxml));

// ── deleteByName ─────────────────────────────────────────────────────────

it('manda o tema do deleteByName na query string, não no corpo', function () {
    // No corpo, o servidor não lia o tema e apagava o `default`.
    FusionReport::templates()->deleteByName('vendas', 'azul');

    $sent = ($this->sent)();

    expect($sent['method'])->toBe('DELETE')
        ->and($sent['path'])->toBe('/api/v1/reports/name/vendas')
        ->and($sent['query'])->toBe(['theme' => 'azul'])
        ->and($sent['body'])->toBe('');
});

it('usa o tema default no deleteByName sem tema', function () {
    FusionReport::templates()->deleteByName('vendas');

    expect(($this->sent)()['query'])->toBe(['theme' => 'default']);
});

// ── updateByName ─────────────────────────────────────────────────────────

it('localiza o template do updateByName pelo tema na query string', function () {
    // No corpo, `theme` é o tema NOVO: o servidor localizava o `default` e
    // renomeava o tema dele.
    FusionReport::templates()->updateByName('vendas', $this->jrxml, 'azul', 'Descrição');

    $sent = ($this->sent)();

    expect($sent['method'])->toBe('POST')
        ->and($sent['path'])->toBe('/api/v1/reports/name/vendas')
        ->and($sent['query'])->toBe(['theme' => 'azul'])
        ->and($sent['parts']->keys()->all())->toEqualCanonicalizing(['description', 'file'])
        ->and($sent['parts']['description'])->toBe('Descrição');
});

it('não manda tema no updateByName sem tema', function () {
    FusionReport::templates()->updateByName('vendas', $this->jrxml);

    expect(($this->sent)()['query'])->toBe([]);
});

it('atualiza só a descrição pelo nome, sem arquivo', function () {
    FusionReport::templates()->updateByName('vendas', theme: 'azul', description: 'Outra');

    expect(($this->sent)()['parts']->keys()->all())->toBe(['description']);
});

// ── update ───────────────────────────────────────────────────────────────

it('atualiza por id sem arquivo, só com os campos informados', function () {
    FusionReport::templates()->update('t-1', description: 'Nova');

    $sent = ($this->sent)();

    expect($sent['path'])->toBe('/api/v1/reports/t-1')
        ->and($sent['parts']->all())->toBe(['description' => 'Nova']);
});

it('manda o arquivo e os campos no update por id', function () {
    FusionReport::templates()->update('t-1', $this->jrxml, name: 'novo', theme: 'verde');

    expect(($this->sent)()['parts']->keys()->all())->toEqualCanonicalizing(['name', 'theme', 'file']);
});

// ── upload ───────────────────────────────────────────────────────────────

it('cadastra em multipart com o arquivo', function () {
    FusionReport::templates()->upload($this->jrxml, 'vendas', 'azul');

    $sent = ($this->sent)();

    expect($sent['path'])->toBe('/api/v1/reports')
        ->and($sent['parts']->keys()->all())->toEqualCanonicalizing(['name', 'theme', 'file'])
        ->and($sent['parts']['theme'])->toBe('azul');
});

// ── quota ────────────────────────────────────────────────────────────────

it('lê o limite de templates do plano', function (mixed $limit, ?int $expected) {
    $this->response = ['data' => [
        'reports' => ['data' => [], 'last_page' => 1],
        'template_limit' => $limit,
        'template_count' => 3,
    ]];

    expect(FusionReport::templates()->quota())->toBe(['limit' => $expected, 'count' => 3]);
})->with([
    'com limite' => [10, 10],
    'sem limite' => [null, null],
]);

// ── list ─────────────────────────────────────────────────────────────────

it('percorre todas as páginas do list()', function () {
    $this->response = function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
        $page = (int) $query['page'];

        return ['data' => ['reports' => [
            'data' => [['id' => "t-{$page}", 'name' => 'x', 'theme' => 'default']],
            'last_page' => 3,
        ]]];
    };

    $ids = FusionReport::templates()->list()->map->id()->all();

    expect($ids)->toBe(['t-1', 't-2', 't-3']);
});

it('para o list() na primeira página vazia, mesmo com last_page alto', function () {
    // Antes: uma requisição por página até o last_page anunciado.
    $this->response = function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

        return ['data' => ['reports' => [
            'data' => (int) $query['page'] === 1 ? [['id' => 't-1']] : [],
            'last_page' => 100000,
        ]]];
    };

    expect(FusionReport::templates()->list())->toHaveCount(1);

    Http::assertSentCount(2);
});

it('recusa listar mais páginas que o limite', function () {
    $this->response = ['data' => ['reports' => ['data' => [['id' => 't-1']], 'last_page' => 5000]]];

    expect(fn () => FusionReport::templates()->list())
        ->toThrow(FusionReportException::class, 'Template listing exceeded 1000 pages (server reported last_page = 5000).');

    Http::assertSentCount(1000);
});

// ── ids na URL ───────────────────────────────────────────────────────────

it('codifica o id do template na URL', function (string $call) {
    match ($call) {
        'find' => FusionReport::templates()->find('../files/a?b'),
        'delete' => FusionReport::templates()->delete('../files/a?b'),
        'update' => FusionReport::templates()->update('../files/a?b', description: 'x'),
        'downloadJrxml' => FusionReport::templates()->downloadJrxml('../files/a?b'),
    };

    expect(($this->sent)()['path'])->toStartWith('/api/v1/reports/..%2Ffiles%2Fa%3Fb');
})->with(['find', 'delete', 'update', 'downloadJrxml']);

it('codifica o id da geração e do arquivo na URL', function () {
    $this->response = ['data' => ['id' => 'g/1']];

    FusionReport::generation('../x')->get();
    FusionReport::file('../y')->export('pdf');
    FusionReport::reportById('../z')->format('pdf')->generate();

    $paths = collect(Http::recorded())->map(fn ($pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->all();

    expect($paths)->toBe([
        '/api/v1/generations/..%2Fx',
        '/api/v1/files/..%2Fy/export',
        '/api/v1/reports/..%2Fz/generate',
    ]);
});
