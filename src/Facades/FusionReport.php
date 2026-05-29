<?php

namespace RiseTechApps\FusionReport\Facades;

use Illuminate\Support\Facades\Facade;
use RiseTechApps\FusionReport\Builders\ReportRequestBuilder;
use RiseTechApps\FusionReport\FusionReportClient;
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
}
