<?php

use Illuminate\Support\Facades\Route;
use RiseTechApps\FusionReport\Http\Controllers\WebhookController;

Route::middleware('api')
    ->post('/fusion/webhook', WebhookController::class)
    ->name('fusion-report.webhook');
