<?php

namespace RiseTechApps\FusionReport\Console\Commands;

use Illuminate\Console\Command;
use RiseTechApps\FusionReport\Definitions\ReportDefinition;
use RiseTechApps\FusionReport\Definitions\ThemeFusion;
use RiseTechApps\FusionReport\Facades\FusionReport;
use RiseTechApps\FusionReport\Resources\TemplateResource;
use Throwable;

class SyncReportsCommand extends Command
{
    protected $signature = 'fusion-report:sync
                            {--force : Atualiza os templates que já estão registrados no servidor}';

    protected $description = 'Registra no servidor os templates definidos em config(fusion-report.reports).';

    public function handle(): int
    {
        $reports = config('fusion-report.reports', []);

        if (empty($reports)) {
            $this->warn('Nenhum report configurado em fusion-report.reports.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        // Templates já registrados no servidor, indexados por name + theme:
        // no servidor a identidade de um template é o par (name, theme).
        $existing = FusionReport::templates()->list()
            ->keyBy(fn(TemplateResource $t) => $this->key($t->name(), $t->theme()));

        $created = $updated = $skipped = $failed = 0;
        $rows = [];

        foreach ($reports as $class) {
            /** @var ReportDefinition $definition */
            $definition = app($class);
            $name = $definition->name();
            $themes = $definition->themes();

            if (empty($themes)) {
                $rows[] = [$name, '—', 'falhou', 'nenhum tema definido (themes() vazio)'];
                $failed++;
                continue;
            }

            /** @var ThemeFusion $theme */
            foreach ($themes as $theme) {
                $themeName = $theme->name();

                try {
                    $theme->validate();
                } catch (Throwable $e) {
                    $rows[] = [$name, $themeName, 'falhou', $e->getMessage()];
                    $failed++;
                    continue;
                }

                $path = $theme->path();
                $resources = $theme->resources();
                $description = $theme->description() ?? '';
                $alreadyRegistered = $existing->has($this->key($name, $themeName));

                try {
                    if ($alreadyRegistered && ! $force) {
                        $rows[] = [$name, $themeName, 'ignorado', 'já registrado (use --force para atualizar)'];
                        $skipped++;
                        continue;
                    }

                    if ($alreadyRegistered) {
                        FusionReport::templates()->updateByName($name, $path, $themeName, $description, $resources);
                        $rows[] = [$name, $themeName, 'atualizado', $resources ? 'com resources' : 'sem resources'];
                        $updated++;
                    } else {
                        FusionReport::templates()->upload($path, $name, $themeName, $description, $resources);
                        $rows[] = [$name, $themeName, 'cadastrado', $resources ? 'com resources' : 'sem resources'];
                        $created++;
                    }
                } catch (Throwable $e) {
                    $rows[] = [$name, $themeName, 'falhou', $e->getMessage()];
                    $failed++;
                }
            }
        }

        $this->table(['Template', 'Tema', 'Ação', 'Detalhe'], $rows);
        $this->info("Cadastrados: {$created} | Atualizados: {$updated} | Ignorados: {$skipped} | Falhas: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function key(string $name, string $theme): string
    {
        return $name . '|' . $theme;
    }
}
