<?php

namespace RiseTechApps\FusionReport\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use RiseTechApps\FusionReport\Contracts\WebhookHandler;
use RiseTechApps\FusionReport\Exceptions\WebhookSignatureException;
use RiseTechApps\FusionReport\Webhook\WebhookPayload;

class WebhookController extends Controller
{
    public function __construct(private readonly WebhookHandler $handler) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('fusion-report.webhook.secret') ?: null;

        try {
            $webhook = WebhookPayload::fromRequest($request, $secret);
        } catch (WebhookSignatureException $e) {
            Log::warning('[FusionReport] Webhook signature failure: ' . $e->getMessage(), [
                'raw_body' => $request->getContent(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $this->handler->handle($webhook);

        return response()->json(['ok' => true]);
    }
}
