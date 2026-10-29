<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RiseTechApps\FusionReport\Events\ReportGenerationCompleted;
use RiseTechApps\FusionReport\Events\ReportWebhookFailed;
use RiseTechApps\FusionReport\Facades\FusionReport;
use RiseTechApps\FusionReport\Jobs\ProcessFusionReportWebhook;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;
use RiseTechApps\FusionReport\Tests\Fixtures\WebhookReport;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'fusion-report.log_generations' => true,
        'fusion-report.webhook.secret' => 'segredo',
    ]);

    WebhookReport::reset();
    FusionReport::register('webhook_report', WebhookReport::class);

    // Headers como o servidor manda: HMAC de "{timestamp}.{corpo}".
    $this->signed = function (string $body, ?int $timestamp = null, string $secret = 'segredo', ?string $id = 'whd_1') {
        $timestamp ??= now()->getTimestamp();

        return array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_ID' => $id,
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ], fn ($v) => $v !== null);
    };

    // Mesmo id em toda entrega, como nas novas tentativas do servidor.
    $this->deliver = function (array $payload, ?string $id = 'whd_1') {
        $body = json_encode($payload);

        return $this->call('POST', '/fusion/webhook', [], [], [], ($this->signed)($body, id: $id), $body);
    };

    $this->payload = [
        'id' => 'g-1',
        'status' => 'completed',
        'template_name' => 'webhook_report',
        'frp_id' => 'frp-1',
        'files' => [['format' => 'pdf', 'download_url' => 'https://files.test/g-1.pdf']],
    ];

    $this->createRecord = fn () => FusionReportGeneration::create([
        'generation_uuid' => 'g-1',
        'name' => 'webhook_report',
        'formats' => ['pdf'],
        'status' => 'pending',
    ]);
});

it('responde 503 ao callback que chega antes da linha existir, e processa na reentrega', function () {
    Event::fake([ReportGenerationCompleted::class]);

    ($this->deliver)($this->payload)->assertStatus(503);

    expect(FusionReportGeneration::count())->toBe(0)
        ->and(WebhookReport::$calls)->toBe(0);
    Event::assertNotDispatched(ReportGenerationCompleted::class);

    // generateAsync() gravou a linha; o servidor reentrega.
    ($this->createRecord)();

    ($this->deliver)($this->payload)->assertOk();

    expect(FusionReportGeneration::first()->status)->toBe('completed')
        ->and(WebhookReport::$calls)->toBe(1);
    Event::assertDispatchedTimes(ReportGenerationCompleted::class, 1);
});

it('reexecuta onWebhookReceived na reentrega quando a primeira tentativa falhou', function () {
    ($this->createRecord)();
    WebhookReport::$fail = true;

    ($this->deliver)($this->payload)->assertServerError();

    // Marcas de "já processado" desfeitas: status volta, cache liberado.
    expect(FusionReportGeneration::first()->status)->toBe('pending');

    WebhookReport::$fail = false;

    ($this->deliver)($this->payload)->assertOk();

    expect(WebhookReport::$calls)->toBe(2)
        ->and(FusionReportGeneration::first()->status)->toBe('completed');
});

it('ignora reentrega de callback já processado com sucesso', function () {
    ($this->createRecord)();

    ($this->deliver)($this->payload)->assertOk();
    ($this->deliver)($this->payload)->assertOk();

    expect(WebhookReport::$calls)->toBe(1);
});

it('reexecuta na reentrega também com log_generations desligado', function () {
    config(['fusion-report.log_generations' => false]);
    WebhookReport::$fail = true;

    ($this->deliver)($this->payload)->assertServerError();

    WebhookReport::$fail = false;

    ($this->deliver)($this->payload)->assertOk();
    ($this->deliver)($this->payload)->assertOk();

    expect(WebhookReport::$calls)->toBe(2);
});

it('entrega o callback à definition registrada só em runtime', function () {
    // WebhookReport não está em fusion-report.reports — só no registry, via register().
    expect(config('fusion-report.reports'))->not->toContain(WebhookReport::class);

    ($this->createRecord)();

    ($this->deliver)($this->payload)->assertOk();

    expect(WebhookReport::$calls)->toBe(1);
});

it('recusa callback com assinatura inválida', function () {
    ($this->createRecord)();

    $this->call('POST', '/fusion/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=errada',
    ], json_encode($this->payload))->assertUnauthorized();

    expect(FusionReportGeneration::first()->status)->toBe('pending')
        ->and(WebhookReport::$calls)->toBe(0);
});

it('não grava o corpo do callback no log', function (array $payload, string $secret, string $message) {
    Log::spy();

    $body = json_encode($payload);
    $this->call('POST', '/fusion/webhook', [], [], [], ($this->signed)($body, secret: $secret), $body);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $logged, array $context) => str_contains($logged, $message)
            && ! array_key_exists('raw_body', $context)
            && ! str_contains(json_encode($context), 'files.test'))
        ->once();
})->with([
    'assinatura inválida' => [['id' => 'g-1', 'files' => [['download_url' => 'https://files.test/x.pdf']]], 'errado', 'signature failure'],
    'payload malformado' => [['files' => [['download_url' => 'https://files.test/x.pdf']]], 'segredo', 'Malformed'],
]);

it('registra a geração perdida quando o job do webhook esgota as tentativas', function () {
    Log::spy();

    $job = new ProcessFusionReportWebhook($this->payload);
    $job->failed(new RuntimeException('SMTP down'));

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => $context === [
            'generation_uuid' => 'g-1',
            'status' => 'completed',
            'template_name' => 'webhook_report',
            'error' => 'SMTP down',
        ])
        ->once();
});

it('dispara ReportWebhookFailed com o payload quando o job esgota as tentativas', function () {
    Event::fake([ReportWebhookFailed::class]);
    $error = new RuntimeException('SMTP down');

    (new ProcessFusionReportWebhook($this->payload))->failed($error);

    Event::assertDispatched(
        ReportWebhookFailed::class,
        fn ($event) => $event->exception === $error
            && $event->webhook->uuid() === 'g-1'
            && $event->webhook->templateName() === 'webhook_report'
            && $event->webhook->downloads()->first()->url() === 'https://files.test/g-1.pdf',
    );
});

// ── evento de teste do painel ────────────────────────────────────────────

it('aceita o webhook de teste do painel sem tratar como geração', function () {
    $response = ($this->deliver)([
        'id' => 'evt_01m6216s17ak8nq4qdstkkan9p',
        'event' => 'webhook.test',
        'created_at' => '2026-10-28T19:53:45+00:00',
        'data' => ['message' => 'Teste de webhook'],
    ]);

    $response->assertOk()->assertExactJson(['ok' => true, 'test' => true]);

    expect(WebhookReport::$calls)->toBe(0)
        ->and(FusionReportGeneration::count())->toBe(0);
});

it('recusa o webhook de teste com assinatura errada', function () {
    $body = json_encode(['id' => 'evt_1', 'event' => 'webhook.test', 'data' => []]);

    $this->call('POST', '/fusion/webhook', [], [], [], ($this->signed)($body, secret: 'outro-segredo'), $body)
        ->assertStatus(401)
        ->assertExactJson(['error' => 'Unauthorized', 'reason' => 'invalid_signature']);
});

it('diz no 401 que o header de assinatura não chegou', function () {
    $this->postJson('/fusion/webhook', ['id' => 'evt_1', 'event' => 'webhook.test'])
        ->assertStatus(401)
        ->assertExactJson(['error' => 'Unauthorized', 'reason' => 'missing_signature']);
});

it('aceita a assinatura sem o prefixo sha256= e em maiúsculas', function (callable $format) {
    $body = json_encode(['id' => 'evt_1', 'event' => 'webhook.test']);
    $timestamp = now()->getTimestamp();
    $hex = hash_hmac('sha256', $timestamp.'.'.$body, 'segredo');

    $this->call('POST', '/fusion/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_WEBHOOK_SIGNATURE' => $format($hex),
    ], $body)->assertOk();
})->with([
    'hex puro' => [fn (string $hex) => $hex],
    'maiúsculas' => [fn (string $hex) => 'SHA256='.strtoupper($hex)],
    'espaço nas pontas' => [fn (string $hex) => " sha256={$hex} "],
]);

// ── timestamp assinado e X-Webhook-Id ────────────────────────────────────

it('recusa a assinatura só do corpo, sem o timestamp', function () {
    ($this->createRecord)();
    $body = json_encode($this->payload);

    $this->call('POST', '/fusion/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WEBHOOK_TIMESTAMP' => (string) now()->getTimestamp(),
        'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'segredo'),
    ], $body)->assertStatus(401)->assertJson(['reason' => 'invalid_signature']);

    expect(WebhookReport::$calls)->toBe(0);
});

it('recusa entrega sem X-Webhook-Timestamp', function () {
    $body = json_encode($this->payload);
    $headers = ($this->signed)($body);
    unset($headers['HTTP_X_WEBHOOK_TIMESTAMP']);

    $this->call('POST', '/fusion/webhook', [], [], [], $headers, $body)
        ->assertStatus(401)
        ->assertExactJson(['error' => 'Unauthorized', 'reason' => 'missing_timestamp']);
});

it('recusa timestamp com mais de 5 minutos de diferença', function (int $offset) {
    ($this->createRecord)();
    $body = json_encode($this->payload);

    $this->call('POST', '/fusion/webhook', [], [], [], ($this->signed)($body, now()->getTimestamp() + $offset), $body)
        ->assertStatus(401)
        ->assertExactJson(['error' => 'Unauthorized', 'reason' => 'expired_timestamp']);

    expect(FusionReportGeneration::first()->status)->toBe('pending')
        ->and(WebhookReport::$calls)->toBe(0);
})->with([
    'no passado' => [-301],
    'no futuro' => [301],
]);

it('aceita timestamp dentro da janela', function () {
    ($this->createRecord)();
    $body = json_encode($this->payload);

    $this->call('POST', '/fusion/webhook', [], [], [], ($this->signed)($body, now()->getTimestamp() - 299), $body)
        ->assertOk();

    expect(WebhookReport::$calls)->toBe(1);
});

it('ignora X-Webhook-Id já processado', function () {
    ($this->createRecord)();

    ($this->deliver)($this->payload)->assertExactJson(['ok' => true]);

    // Mesmo id com outro conteúdo: só o id já basta para descartar.
    ($this->deliver)(['status' => 'failed'] + $this->payload)
        ->assertOk()
        ->assertExactJson(['ok' => true, 'duplicate' => true]);

    expect(WebhookReport::$calls)->toBe(1)
        ->and(FusionReportGeneration::first()->status)->toBe('completed');
});

it('libera o X-Webhook-Id quando o processamento falha', function () {
    ($this->createRecord)();
    WebhookReport::$fail = true;

    ($this->deliver)($this->payload)->assertServerError();

    expect(Cache::has('fusion-report:webhook-delivery:whd_1'))->toBeFalse();

    WebhookReport::$fail = false;

    ($this->deliver)($this->payload)->assertExactJson(['ok' => true]);

    expect(WebhookReport::$calls)->toBe(2);
});

it('enfileira com o X-Webhook-Id e o libera quando o job esgota as tentativas', function () {
    Queue::fake();
    config(['fusion-report.webhook.queue' => 'webhooks']);

    ($this->deliver)($this->payload)->assertExactJson(['ok' => true, 'queued' => true]);
    ($this->deliver)($this->payload)->assertExactJson(['ok' => true, 'duplicate' => true]);

    Queue::assertPushedOn('webhooks', ProcessFusionReportWebhook::class);
    Queue::assertPushed(ProcessFusionReportWebhook::class, 1);

    expect(Cache::has('fusion-report:webhook-delivery:whd_1'))->toBeTrue();

    (new ProcessFusionReportWebhook($this->payload, 'whd_1'))->failed(new RuntimeException('SMTP down'));

    // O "Reenviar" do painel volta a ser aceito.
    expect(Cache::has('fusion-report:webhook-delivery:whd_1'))->toBeFalse();
});
