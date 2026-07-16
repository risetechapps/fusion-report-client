<?php

namespace RiseTechApps\FusionReport;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use RiseTechApps\FusionReport\Console\Commands\SyncReportsCommand;
use RiseTechApps\FusionReport\Contracts\WebhookHandler;
use RiseTechApps\FusionReport\Http\FusionReportHttp;
use RiseTechApps\FusionReport\Models\FusionReportGeneration;
use RiseTechApps\FusionReport\Webhook\FusionReportWebhookHandler;

class FusionReportServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/fusion-report.php', 'fusion-report');

        $this->app->bind(WebhookHandler::class, FusionReportWebhookHandler::class);

        $this->app->singleton(FusionReportClient::class, function ($app) {
            $config = $app->make('config')->get('fusion-report');

            $client = new FusionReportClient(new FusionReportHttp($config), $config);

            foreach ($config['reports'] ?? [] as $name => $definition) {
                $client->register($name, $definition);
            }

            return $client;
        });
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/webhook.php');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('model:prune', ['--model' => FusionReportGeneration::class])->daily();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncReportsCommand::class,
            ]);

            $this->publishes([
                __DIR__ . '/../config/fusion-report.php' => config_path('fusion-report.php'),
            ], 'fusion-report');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'fusion-report-migrations');
        }
    }
}
