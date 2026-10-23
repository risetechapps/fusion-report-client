<?php

namespace RiseTechApps\FusionReport\Tests\Fixtures;

use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;
use RiseTechApps\FusionReport\Datasources\NoDatasource;
use RiseTechApps\FusionReport\Definitions\ReportDefinition;

class TestReport extends ReportDefinition
{
    public function name(): string
    {
        return 'relatorio';
    }

    public function datasource(array $params = []): Datasource
    {
        return new NoDatasource;
    }

    public function themes(): array
    {
        return [];
    }
}
