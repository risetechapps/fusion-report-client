<?php

namespace RiseTechApps\FusionReport\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RiseTechApps\FusionReport\FusionReportServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [FusionReportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('fusion-report.api_key', 'test-key');
        // Sem tabela local: os testes cobrem o contrato HTTP, não o log.
        $app['config']->set('fusion-report.log_generations', false);
        $app['config']->set('fusion-report.reports', [
            'relatorio' => Fixtures\TestReport::class,
            'por_cliente' => Fixtures\ClientScopedReport::class,
        ]);
    }
}
