<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RiseTechApps\FusionReport\Events\ReportGenerationCompleted;
use RiseTechApps\FusionReport\Events\ReportGenerationFailed;
use RiseTechApps\FusionReport\Exceptions\FusionReportException;
use RiseTechApps\FusionReport\Exceptions\ReportNotFoundException;
use RiseTechApps\FusionReport\Exceptions\ServerException;
use RiseTechApps\FusionReport\Facades\FusionReport;

it('usa a URL configurada em fusion-report.url', function () {
    config(['fusion-report.url' => 'https://staging.example.test/']);
    Http::fake(['*' => Http::response(['data' => ['reports' => ['data' => [], 'last_page' => 1]]])]);

    FusionReport::templates()->list();

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://staging.example.test/api/v1/reports'));
});

it('cai na URL padrão sem configuração', function () {
    config(['fusion-report.url' => null]);
    Http::fake(['*' => Http::response(['data' => ['reports' => ['data' => [], 'last_page' => 1]]])]);

    FusionReport::templates()->list();

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://fusionreport.app.br/api/v1/reports'));
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

it('usa os caminhos atuais do servidor no JasperManager', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    FusionReport::jasper()->formats();
    FusionReport::jasper()->health();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/fusion-report/formats'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/fusion-report/health'));
});

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
