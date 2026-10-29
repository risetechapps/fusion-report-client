<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RiseTechApps\FusionReport\Exceptions\RateLimitException;
use RiseTechApps\FusionReport\Facades\FusionReport;

// O que o fusion-report-server 1.1 acrescentou ao contrato: cota nos headers
// da geração, consumo pela API key e histórico de gerações pela API key.

it('lê a cota dos headers da geração', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'g-1', 'status' => 'completed']], 200, [
        'X-Quota-Limit' => '1000',
        'X-Quota-Remaining' => '993',
        'X-Quota-Used' => '7',
        'X-Quota-Reset' => '1795893736',
    ])]);

    $quota = FusionReport::make('relatorio')->format('pdf')->generate()->quota();

    expect($quota->limit())->toBe(1000)
        ->and($quota->remaining())->toBe(993)
        ->and($quota->used())->toBe(7)
        ->and($quota->resetsAt()->getTimestamp())->toBe(1795893736)
        ->and($quota->isUnlimited())->toBeFalse();
});

it('trata plano sem Limit e Remaining como ilimitado', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'g-1', 'status' => 'pending']], 202, [
        'X-Quota-Used' => '12',
        'X-Quota-Reset' => '1795893736',
    ])]);

    $quota = FusionReport::make('relatorio')->format('pdf')->generateAsync()->quota();

    expect($quota->isUnlimited())->toBeTrue()
        ->and($quota->remaining())->toBeNull()
        ->and($quota->used())->toBe(12);
});

it('devolve cota null com servidor sem os headers', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'g-1', 'status' => 'completed']])]);

    expect(FusionReport::make('relatorio')->format('pdf')->generate()->quota())->toBeNull();
});

it('leva a cota e a espera até o fim do período no 429 de cota esgotada', function () {
    $reset = time() + 3600;

    Http::fake(['*' => Http::response(['success' => false, 'message' => 'Quota exceeded.'], 429, [
        'X-Quota-Limit' => '1000',
        'X-Quota-Remaining' => '0',
        'X-Quota-Used' => '1000',
        'X-Quota-Reset' => (string) $reset,
    ])]);

    try {
        FusionReport::make('relatorio')->format('pdf')->generate();
        $this->fail('esperava RateLimitException');
    } catch (RateLimitException $e) {
        expect($e->isQuotaExhausted())->toBeTrue()
            ->and($e->quota()->used())->toBe(1000)
            ->and($e->retryAfter())->toBeGreaterThan(3500)->toBeLessThanOrEqual(3600);
    }
});

it('prefere o Retry-After no 429 de limite de taxa', function () {
    Http::fake(['*' => Http::response(['message' => 'Too Many Attempts.'], 429, [
        'Retry-After' => '30',
        'X-Quota-Remaining' => '500',
        'X-Quota-Used' => '500',
    ])]);

    try {
        FusionReport::make('relatorio')->format('pdf')->generate();
        $this->fail('esperava RateLimitException');
    } catch (RateLimitException $e) {
        expect($e->retryAfter())->toBe(30)
            ->and($e->isQuotaExhausted())->toBeFalse();
    }
});

it('consulta o consumo da conta', function () {
    Http::fake(['*/api/v1/usage' => Http::response(['success' => true, 'data' => [
        'plan' => ['id' => 'p-1', 'name' => 'PRO', 'billing_cycle' => 'monthly'],
        'period' => ['start' => '2026-10-29T19:22:16+00:00', 'end' => '2026-11-28T19:22:16+00:00', 'status' => 'active'],
        'requests' => ['limit' => 1000, 'used' => 7, 'remaining' => 993, 'resets_at' => '2026-11-28T19:22:16+00:00'],
        'overage' => ['requests' => 0],
        'rate_limit' => ['per_minute' => 120],
        'api_key' => ['id' => 'k-1', 'name' => 'Principal', 'expires_at' => null],
    ]])]);

    $usage = FusionReport::usage();

    expect($usage->planName())->toBe('PRO')
        ->and($usage->periodStatus())->toBe('active')
        ->and($usage->limit())->toBe(1000)
        ->and($usage->used())->toBe(7)
        ->and($usage->remaining())->toBe(993)
        ->and($usage->resetsAt()->toIso8601String())->toBe('2026-11-28T19:22:16+00:00')
        ->and($usage->rateLimitPerMinute())->toBe(120)
        ->and($usage->apiKeyId())->toBe('k-1')
        ->and($usage->apiKeyExpiresAt())->toBeNull();

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://fusionreport.app.br/api/v1/usage');
});

it('consulta o consumo por dia', function () {
    Http::fake(['*' => Http::response(['data' => ['from' => '2026-10-28', 'to' => '2026-10-29', 'days' => [
        ['date' => '2026-10-28', 'total' => 0, 'success' => 0, 'client_error' => 0, 'server_error' => 0],
        ['date' => '2026-10-29', 'total' => 12, 'success' => 6, 'client_error' => 1, 'server_error' => 5],
    ]]])]);

    $days = FusionReport::dailyUsage(2);

    expect($days)->toHaveCount(2)
        ->and($days->last()['total'])->toBe(12);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/usage/daily?days=2'));
});

it('recusa dailyUsage fora de 1 a 90 dias sem chamar o servidor', function (int $days) {
    Http::fake();

    expect(fn () => FusionReport::dailyUsage($days))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with([0, 91]);

it('lista o histórico de gerações do template com per_page', function () {
    Http::fake(['*' => Http::response(['data' => ['current_page' => 1, 'data' => [
        ['id' => 'g-2', 'status' => 'completed'],
        ['id' => 'g-1', 'status' => 'failed'],
    ]]])]);

    $generations = FusionReport::templates()->generations('r-1', perPage: 500);

    expect($generations->map->id()->all())->toBe(['g-2', 'g-1']);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/reports/r-1/generations?per_page=100'));
});

it('manda o tema no histórico por nome só quando informado', function () {
    Http::fake(['*' => Http::response(['data' => ['data' => []]])]);

    FusionReport::templates()->generationsByName('vendas');
    FusionReport::templates()->generationsByName('vendas', 'azul');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/reports/name/vendas/generations?per_page=20'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/reports/name/vendas/generations?theme=azul&per_page=20'));
});

it('wait() consulta antes de decidir quando a geração foi criada só com o id', function () {
    Sleep::fake();
    Http::fakeSequence()
        ->push(['data' => ['id' => 'g-5', 'status' => 'processing']])
        ->push(['data' => ['id' => 'g-5', 'status' => 'completed', 'files' => []]]);

    // Antes, sem status carregado, o loop não entrava e voltava `unknown`.
    expect(FusionReport::generation('g-5')->wait()->isCompleted())->toBeTrue();

    Http::assertSentCount(2);
});
