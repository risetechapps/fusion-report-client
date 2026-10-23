<?php

use Illuminate\Support\Facades\Route;
use RiseTechApps\FusionReport\Http\Controllers\WebhookController;

Route::middleware(config('fusion-report.webhook.middleware', ['api']))
    ->post(config('fusion-report.webhook.path', '/fusion/webhook'), WebhookController::class)
    ->name('fusion-report.webhook');
