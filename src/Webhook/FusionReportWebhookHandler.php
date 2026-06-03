<?php

namespace RiseTechApps\FusionReport\Webhook;

use RiseTechApps\FusionReport\Contracts\WebhookHandler;
use RiseTechApps\FusionReport\Definitions\ReportDefinition;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;

class FusionReportWebhookHandler implements WebhookHandler
{
    public function handle(WebhookPayload $webhook): void
    {
        $record = FusionReportGeneration::where('generation_uuid', $webhook->uuid())->first();

        if ($record === null) {
            return;
        }

        $data = ['status' => $webhook->status()];

        if ($webhook->isSuccess()) {
            $data['frp_uuid']      = $webhook->frpId();
            $data['download_urls'] = $webhook->downloads()
                ->mapWithKeys(fn(WebhookDownload $d) => [$d->format() => $d->url()])
                ->toArray();
        }

        $record->update($data);

        $this->dispatchToDefinition($webhook, $record->context ?? []);
    }

    private function dispatchToDefinition(WebhookPayload $webhook, array $context = []): void
    {
        $templateName = $webhook->templateName();

        if ($templateName === null) {
            return;
        }

        $reports = config('fusion-report.reports', []);

        $definitionClass = collect($reports)->first(
            fn($class) => (new $class())->template() === $templateName
        );

        if ($definitionClass === null) {
            return;
        }

        /** @var ReportDefinition $definition */
        $definition = app($definitionClass);
        $definition->onWebhookReceived($webhook);
    }
}
