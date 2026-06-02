<?php

namespace RiseTechApps\FusionReport\Facades;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use RiseTechApps\FusionReport\Builders\ReportRequestBuilder;
use RiseTechApps\FusionReport\FusionReportClient;
use RiseTechApps\FusionReport\Http\Controllers\GenerateController;
use RiseTechApps\FusionReport\Managers\TemplateManager;
use RiseTechApps\FusionReport\Resources\FileResource;
use RiseTechApps\FusionReport\Resources\GenerationResource;

/**
 * @method static ReportRequestBuilder  report(string $template)
 * @method static TemplateManager       templates()
 * @method static FileResource          file(string $fileId)
 * @method static GenerationResource    generation(string $generationId)
 *
 * @see FusionReportClient
 */
class FusionReport extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FusionReportClient::class;
    }

    /**
     * Register the optional report generation route.
     *
     * Call from your app's routes (e.g. routes/api.php):
     *   FusionReport::routes(['middleware' => 'auth:api', 'prefix' => 'api']);
     */
    public static function routes(array $options = []): void
    {
        Route::group($options, function () {

            $middlewares = $options['middleware'] ?? [];

            $middlewares = ['auth:sanctum'];

            Route::middleware($middlewares)->post('/reports/generate', GenerateController::class)
                ->name('fusion-report.generate');
        });
    }
}
