<?php

namespace RiseTechApps\FusionReport\Console\Commands;

use Illuminate\Console\Command;
use RiseTechApps\FusionReport\Definitions\ReportDefinition;
use RiseTechApps\FusionReport\Definitions\ThemeFusion;
use RiseTechApps\FusionReport\Facades\FusionReport;
use RiseTechApps\FusionReport\Resources\TemplateResource;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

use function Laravel\Prompts\multiselect;

class SyncReportsCommand extends Command
{
    protected $signature = 'fusion-report:sync
                            {--force : Interactively selects which templates already registered to update}
                            {--all : Updates all templates already registered, no questions asked}';

    protected $description = 'Registers the templates defined in config(fusion-report.reports on the server)).';

    public function handle(): int
    {
        $reports = config('fusion-report.reports', []);

        if (empty($reports)) {
            $this->warn('No reports configured in fusion-report.reports.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $all = (bool) $this->option('all');

        $this->line('Consulting templates already registered on the server...');

        $existing = FusionReport::templates()->list()
            ->keyBy(fn(TemplateResource $t) => $this->key($t->name(), $t->theme()));

        $items = [];
        foreach ($reports as $class) {
            /** @var ReportDefinition $definition */
            $definition = app($class);
            $name = $definition->name();
            $themes = $definition->themes();

            if (empty($themes)) {
                $items[] = [$name, null];
                continue;
            }

            foreach ($themes as $theme) {
                $items[] = [$name, $theme];
            }
        }

        $forced = $this->resolveForced($items, $existing, $force, $all);

        if ($force && ! $all) {
            $items = array_values(array_filter($items, fn($item) => $item[1] !== null
                && $forced->has($this->key($item[0], $item[1]->name()))));

            if (empty($items)) {
                $this->info('No template selected — nothing to do.');

                return self::SUCCESS;
            }
        }

        $created = $updated = $skipped = $failed = 0;
        $rows = [];

        $bar = $this->output->createProgressBar(count($items));
        $bar->setFormat(
            " %current%/%max% [%bar%] %percent:3s%%\n"
            . " <info>%message%</info>"
        );
        $bar->setMessage('Starting...');
        $bar->start();

        foreach ($items as [$name, $theme]) {
            /** @var ThemeFusion|null $theme */
            if ($theme === null) {
                $bar->setMessage("{$name} — No theme set");
                $this->streamFailure($bar, $name, '—', 'No theme set (empty themes()');
                $rows[] = [$name, '—', 'Failed', 'No theme set (empty themes()'];
                $failed++;
                $bar->advance();
                continue;
            }

            $themeName = $theme->name();
            $bar->setMessage("{$name} | {$themeName}");

            try {
                $theme->validate();
            } catch (Throwable $e) {
                $this->streamFailure($bar, $name, $themeName, $e->getMessage());
                $rows[] = [$name, $themeName, 'falhou', $e->getMessage()];
                $failed++;
                $bar->advance();
                continue;
            }

            $path = $theme->path();
            $resources = $theme->resources();
            $description = $theme->description() ?? '';
            $alreadyRegistered = $existing->has($this->key($name, $themeName));
            $shouldForce = $forced->has($this->key($name, $themeName));

            try {
                if ($alreadyRegistered && ! $shouldForce) {
                    $rows[] = [$name, $themeName, 'Ignored', 'already registered (use --force to update)'];
                    $skipped++;
                } elseif ($alreadyRegistered) {
                    FusionReport::templates()->updateByName($name, $path, $themeName, $description, $resources);
                    $rows[] = [$name, $themeName, 'Updated', $resources ? 'com resources' : 'sem resources'];
                    $updated++;
                } else {
                    FusionReport::templates()->upload($path, $name, $themeName, $description, $resources);
                    $rows[] = [$name, $themeName, 'Registered', $resources ? 'com resources' : 'sem resources'];
                    $created++;
                }
            } catch (Throwable $e) {
                $this->streamFailure($bar, $name, $themeName, $e->getMessage());
                $rows[] = [$name, $themeName, 'failed', $e->getMessage()];
                $failed++;
            }

            $bar->advance();
        }

        $bar->setMessage('Concluído.');
        $bar->finish();
        $this->newLine(2);

        $this->table(['Template', 'Theme', 'Action', 'Detail'], $rows);
        $this->info("Registered: {$created} | Updated: {$updated} | Ignored: {$skipped} | Failures: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function streamFailure(ProgressBar $bar, string $name, string $theme, string $message): void
    {
        $bar->clear();
        $this->output->writeln("  <fg=red>✗</> {$name} | {$theme} — {$message}");
        $bar->display();
    }

    private function resolveForced($items, $existing, bool $force, bool $all)
    {
        if (! $force && ! $all) {
            return collect();
        }

        $registered = collect($items)
            ->filter(fn($item) => $item[1] !== null
                && $existing->has($this->key($item[0], $item[1]->name())))
            ->mapWithKeys(function ($item) {
                $key = $this->key($item[0], $item[1]->name());

                return [$key => "{$item[0]} | {$item[1]->name()}"];
            });

        if ($registered->isEmpty()) {
            $this->line('No templates already registered to update.');

            return collect();
        }

        // --all, ou --force sem terminal interativo (CI), atualiza todos.
        if ($all || ! $this->input->isInteractive()) {
            return $registered->map(fn() => true);
        }

        $selected = multiselect(
            label: 'Which templates to update?',
            options: $registered->all(),
            hint: 'Space checks, Enter confirms. Unmarked are ignored.',
            scroll: 15,
        );

        return collect($selected)->mapWithKeys(fn($key) => [$key => true]);
    }

    private function key(string $name, string $theme): string
    {
        return $name . '|' . $theme;
    }
}
