<?php

namespace RiseTechApps\FusionReport\Tests\Fixtures;

use RiseTechApps\FusionReport\Definitions\ThemeFusion;

/** Erro de config: outra definition com o mesmo name() da SyncReport. */
class SameNameReport extends SyncReport
{
    public function themes(): array
    {
        return [ThemeFusion::make('rosa')->from(static::$jrxml)];
    }
}
