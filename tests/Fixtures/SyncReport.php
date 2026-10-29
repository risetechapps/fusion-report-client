<?php

namespace RiseTechApps\FusionReport\Tests\Fixtures;

use RiseTechApps\FusionReport\Definitions\ThemeFusion;

/** Temas apontando para o .jrxml que o teste criar em `$jrxml`. */
class SyncReport extends TestReport
{
    public static string $jrxml = '';

    public function name(): string
    {
        return 'vendas';
    }

    public function themes(): array
    {
        return [
            ThemeFusion::make('azul')->from(static::$jrxml),
            ThemeFusion::make('verde')->from(static::$jrxml),
        ];
    }
}
