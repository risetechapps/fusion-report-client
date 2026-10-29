<?php

namespace RiseTechApps\FusionReport\Tests\Fixtures;

use RiseTechApps\FusionReport\Definitions\ThemeFusion;

/** Erro de config: o mesmo tema declarado duas vezes. */
class DuplicateThemeReport extends SyncReport
{
    public function name(): string
    {
        return 'estoque';
    }

    public function themes(): array
    {
        return [
            ThemeFusion::make('azul')->from(static::$jrxml),
            ThemeFusion::make('azul')->from(static::$jrxml),
        ];
    }
}
