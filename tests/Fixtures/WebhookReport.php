<?php

namespace RiseTechApps\FusionReport\Tests\Fixtures;

use RiseTechApps\FusionReport\Webhook\WebhookPayload;

/** Conta as chamadas de onWebhookReceived() e pode falhar sob demanda. */
class WebhookReport extends TestReport
{
    public static int $calls = 0;

    public static bool $fail = false;

    public static function reset(): void
    {
        static::$calls = 0;
        static::$fail = false;
    }

    public function name(): string
    {
        return 'webhook_report';
    }

    public function onWebhookReceived(WebhookPayload $payload, array $context = []): void
    {
        static::$calls++;

        if (static::$fail) {
            throw new \RuntimeException('SMTP down');
        }
    }
}
