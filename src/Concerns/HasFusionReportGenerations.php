<?php

namespace RiseTechApps\FusionReport\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;

trait HasFusionReportGenerations
{
    public function fusionReportGenerations(): MorphMany
    {
        return $this->morphMany(FusionReportGeneration::class, 'loggable');
    }
}
