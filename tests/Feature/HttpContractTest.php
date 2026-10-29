<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RiseTechApps\FusionReport\Events\ReportGenerationCompleted;
use RiseTechApps\FusionReport\Events\ReportGenerationFailed;
use RiseTechApps\FusionReport\Events\ReportGenerationStarted;
use RiseTechApps\FusionReport\Exceptions\AuthenticationException;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Exceptions\ReportNotFoundException;
use RiseTechApps\FusionReport\Exceptions\ServerException;
use RiseTechApps\FusionReport\Facades\FusionReport;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Resources\GenerationResource;
use RiseTechApps\FusionReport\Resources\TemplateResource;

it('envia as requisições para BASE_URL, mesmo com fusion-report.url no config', function () {
    config(['fusion-report.url' => 'https://staging.example.test/']);
    Http::fake(['*' => Http::response(['data' => ['reports' => ['data' => [], 'last_page' => 1]]])]);

    FusionReport::templates()->list();

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://fusionreport.app.br/api/v1/reports'));
});

it('não reaproveita a api_key de quando o singleton foi criado', function () {
    Http::fake(['*' => Http::response(['data' => ['reports' => ['data' => [], 'last_page' => 1]]])]);

    // Resolve o singleton com a chave do TestCase ainda configurada.
    app(FusionReportHttp::class);

    config(['fusion-report.api_key' => null]);

    expect(fn () => FusionReport::templates()->list())->toThrow(AuthenticationException::class);

    Http::assertNothingSent();
});

it('converte 5xx em ServerException, que continua sendo FusionReportException', function (int $status) {
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'Renderer down.'], $status)]);

    try {
        FusionReport::make('relatorio')->format('pdf')->generate();
        $this->fail('esperava exceção');
    } catch (ServerException $e) {
        expect($e)->toBeInstanceOf(FusionReportException::class)
            ->and($e->status())->toBe($status)
            ->and($e->isRendererFailure())->toBe($status === 502)
            ->and($e->getMessage())->toBe('Renderer down.');
    }
})->with([500, 502]);

it('mantém 410 (arquivo expirado) como ReportNotFoundException', function () {
    Http::fake(['*' => Http::response(['success' => false, 'message' => 'File has expired.'], 410)]);

    FusionReport::make('relatorio')->format('pdf')->generate();
})->throws(ReportNotFoundException::class, 'File has expired.');

it('codifica o nome do template na URL', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    FusionReport::templates()->deleteByName('Vendas / Mês?');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/v1/reports/name/Vendas%20%2F%20M%C3%AAs%3F'));
});

it('lê o tamanho do arquivo gerado', function (mixed $size, ?int $expected) {
    expect(FusionReport::file('f-1')->size())->toBeNull();

    $generation = new GenerationResource(
        ['id' => 'g-1', 'files' => [['id' => 'f-1', 'size' => $size]]],
        app(FusionReportHttp::class),
    );

    expect($generation->files()->first()->size())->toBe($expected);
})->with([
    'bytes' => [48213, 48213],
    'sem tamanho' => [null, null],
]);

it('não dispara Completed quando a geração síncrona volta pendente', function () {
    // O renderizador enfileirou o job: o servidor devolve a geração `pending`.
    Event::fake([ReportGenerationCompleted::class, ReportGenerationFailed::class]);
    Http::fake(['*' => Http::response(['data' => ['id' => 'g-1', 'status' => 'pending', 'mode' => 'async']], 200)]);

    $generation = FusionReport::make('relatorio')->format('pdf')->generate();

    expect($generation->isPending())->toBeTrue();
    Event::assertNotDispatched(ReportGenerationCompleted::class);
    Event::assertNotDispatched(ReportGenerationFailed::class);
});

it('dispara Completed quando a geração síncrona conclui', function () {
    Event::fake([ReportGenerationCompleted::class]);
    Http::fake(['*' => Http::response(['data' => ['id' => 'g-2', 'status' => 'completed', 'mode' => 'sync', 'files' => []]], 200)]);

    FusionReport::make('relatorio')->format('pdf')->generate();

    Event::assertDispatched(ReportGenerationCompleted::class);
});

it('wait() reconsulta a geração enfileirada até ela concluir', function () {
    Sleep::fake();
    Http::fakeSequence()
        ->push(['data' => ['id' => 'g-3', 'status' => 'pending', 'mode' => 'async']], 202)
        ->push(['data' => ['id' => 'g-3', 'status' => 'processing']])
        ->push(['data' => ['id' => 'g-3', 'status' => 'completed', 'files' => []]]);

    $generation = FusionReport::make('relatorio')->format('pdf')->generate();

    expect($generation->isPending())->toBeTrue()
        ->and($generation->wait()->isCompleted())->toBeTrue();

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/api/v1/generations/g-3'));
    Sleep::assertSleptTimes(2);
});

it('wait() desiste quando o prazo acaba', function () {
    Sleep::fake();
    Http::fake(['*' => Http::response(['data' => ['id' => 'g-4', 'status' => 'pending', 'mode' => 'async']], 202)]);

    FusionReport::make('relatorio')->format('pdf')->generate()->wait(timeoutSeconds: 0);
})->throws(FusionReportException::class, 'still pending');

it('gera sem dono quando o usuário autenticado não é um Model', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'g-5', 'status' => 'pending', 'mode' => 'async']], 200)]);
    $this->actingAs(new GenericUser(['id' => 1]));

    expect(FusionReport::make('relatorio')->format('pdf')->generateAsync()->id())->toBe('g-5');
});

it('falha com a causa real quando a resposta 2xx vem sem id', function (string $mode) {
    // Ex.: página de manutenção ou proxy respondendo 200 no lugar do servidor.
    Event::fake([ReportGenerationStarted::class]);
    Http::fake(['*' => Http::response(['data' => ['status' => 'pending']], 200)]);

    try {
        $builder = FusionReport::make('relatorio')->format('pdf');
        $mode === 'sync' ? $builder->generate() : $builder->generateAsync();
        $this->fail('esperava exceção');
    } catch (FusionReportException $e) {
        expect($e->getMessage())->toBe('Fusion Report Server response has no generation id (received keys: status).');
    }

    Event::assertNotDispatched(ReportGenerationStarted::class);
})->with(['sync', 'async']);

it('falha com mensagem clara quando o template listado vem sem id', function () {
    expect(fn () => (new TemplateResource([]))->id())
        ->toThrow(FusionReportException::class, 'has no template id (received keys: none)');
});
