<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RiseTechApps\FusionReport\Events\ReportGenerationCompleted;
use RiseTechApps\FusionReport\Facades\FusionReport;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;
use RiseTechApps\FusionReport\Tests\Fixtures\WebhookReport;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'fusion-report.log_generations' => true,
        'fusion-report.webhook.secret'  => 'segredo',
    ]);

    WebhookReport::reset();
    FusionReport::register('webhook_report', WebhookReport::class);

    $this->deliver = function (array $payload) {
        $body = json_encode($payload);

        return $this->call('POST', '/fusion/webhook', [], [], [], [
            'CONTENT_TYPE'             => 'application/json',
            'HTTP_ACCEPT'              => 'application/json',
            'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, 'segredo'),
        ], $body);
    };

    $this->payload = [
        'id'            => 'g-1',
        'status'        => 'completed',
        'template_name' => 'webhook_report',
        'frp_id'        => 'frp-1',
        'files'         => [['format' => 'pdf', 'download_url' => 'https://files.test/g-1.pdf']],
    ];

    $this->createRecord = fn() => FusionReportGeneration::create([
        'generation_uuid' => 'g-1',
        'name'            => 'webhook_report',
        'formats'         => ['pdf'],
        'status'          => 'pending',
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
        'CONTENT_TYPE'             => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=errada',
    ], json_encode($this->payload))->assertUnauthorized();

    expect(FusionReportGeneration::first()->status)->toBe('pending')
        ->and(WebhookReport::$calls)->toBe(0);
});

it('não grava o corpo do callback no log', function (array $payload, string $signature) {
    \Illuminate\Support\Facades\Log::spy();

    $this->call('POST', '/fusion/webhook', [], [], [], [
        'CONTENT_TYPE'             => 'application/json',
        'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
    ], json_encode($payload));

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(fn(string $message, array $context) => ! array_key_exists('raw_body', $context)
            && ! str_contains(json_encode($context), 'files.test'))
        ->once();
})->with([
    'assinatura inválida' => [['id' => 'g-1', 'files' => [['download_url' => 'https://files.test/x.pdf']]], 'sha256=errada'],
    'payload malformado'  => [
        ['files' => [['download_url' => 'https://files.test/x.pdf']]],
        'sha256=' . hash_hmac('sha256', json_encode(['files' => [['download_url' => 'https://files.test/x.pdf']]]), 'segredo'),
    ],
]);

it('registra a geração perdida quando o job do webhook esgota as tentativas', function () {
    \Illuminate\Support\Facades\Log::spy();

    $job = new \RiseTechApps\FusionReport\Jobs\ProcessFusionReportWebhook($this->payload);
    $job->failed(new \RuntimeException('SMTP down'));

    \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
        ->withArgs(fn(string $message, array $context) => $context === [
            'generation_uuid' => 'g-1',
            'status'          => 'completed',
            'template_name'   => 'webhook_report',
            'error'           => 'SMTP down',
        ])
        ->once();
});
