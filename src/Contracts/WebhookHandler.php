<?php

namespace RiseTechApps\FusionReport\Contracts;

use RiseTechApps\FusionReport\Webhook\WebhookPayload;

interface WebhookHandler
{
    public function handle(WebhookPayload $webhook): void;
}
