<?php

namespace RiseTechApps\FusionReport\Datasources;

use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;

class NoDatasource implements Datasource
{
    public static function make(): static
    {
        return new static();
    }

    public function type(): string
    {
        return 'none';
    }

    public function config(): array
    {
        return [];
    }
}
