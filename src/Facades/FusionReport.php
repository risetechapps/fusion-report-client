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
        // O middleware informado pelo caller vence; 'auth:sanctum' é só o
        // padrão de quem não informa nada. O group já aplica $options, então a
        // rota interna não repete o middleware.
        $options = array_merge(['middleware' => ['auth:sanctum']], $options);

        Route::group($options, function () {
            Route::post('/reports/generate', GenerateController::class)
                ->name('fusion-report.generate');
        });
    }
}
