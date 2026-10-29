<?php

namespace RiseTechApps\FusionReport\Tests\Fixtures;

use RiseTechApps\FusionReport\Definitions\ThemeFusion;

/** Erro de config: tema sem nome. */
class EmptyThemeReport extends SyncReport
{
    public function name(): string
    {
        return 'compras';
    }

    public function themes(): array
    {
        return [ThemeFusion::make(' ')->from(static::$jrxml)];
    }
}
