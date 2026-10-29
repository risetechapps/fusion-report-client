<?php

namespace RiseTechApps\FusionReport\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RiseTechApps\FusionReport\Definitions\ReportDefinition;
use RiseTechApps\FusionReport\Definitions\ThemeFusion;
use RiseTechApps\FusionReport\Facades\FusionReport;
use RiseTechApps\FusionReport\Resources\TemplateResource;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

class SyncReportsCommand extends Command
{
    protected $signature = 'fusion-report:sync
                            {--force : Interactively select which already registered templates to update}
                            {--all : Update all already registered templates, without asking}
                            {--prune : Remove from the server the templates (name + theme) no longer defined in config}';

    protected $description = 'Registers on the server the templates defined in config(fusion-report.reports).';

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
            ->keyBy(fn (TemplateResource $t) => $this->key($t->name(), $t->theme()));

        $items = [];
        // Chave do config de cada item, no mesmo índice: só a validação usa.
        $sources = [];
        foreach ($reports as $configKey => $class) {
            /** @var ReportDefinition $definition */
            $definition = app($class);
            $name = $definition->name();
            $themes = $definition->themes();

            if (empty($themes)) {
                $items[] = [$name, null, $class];
                $sources[] = (string) $configKey;

                continue;
            }

            foreach ($themes as $theme) {
                $items[] = [$name, $theme, $class];
                $sources[] = (string) $configKey;
            }
        }

        // Erro de config vira o 4º elemento do item: ele aparece como Failed e
        // nunca é enviado, mas o resto do sync segue.
        foreach ($this->configErrors($items, $sources) as $index => $error) {
            $items[$index][3] = $error;
        }

        // Guardado antes do filtro do --force: o prune compara com TUDO que o
        // config declara, não só com o que foi selecionado para atualizar.
        $declared = $items;
        $prune = (bool) $this->option('prune');

        $valid = array_values(array_filter($items, fn ($item) => ! isset($item[3])));

        $forced = $this->resolveForced($valid, $existing, $force, $all);

        if ($force && ! $all) {
            // Os inválidos ficam, para o erro aparecer na tabela.
            $items = array_values(array_filter($items, fn ($item) => isset($item[3])
                || ($item[1] !== null && $forced->has($this->key($item[0], $item[1]->name())))));

            if (empty($items)) {
                $this->info('No template selected — nothing to update.');

                return $prune ? $this->prune($declared, $existing) : self::SUCCESS;
            }
        }

        $this->warnAboutQuota(array_values(array_filter($items, fn ($item) => ! isset($item[3]))), $existing);

        $created = $updated = $skipped = $failed = 0;
        $rows = [];

        $bar = $this->output->createProgressBar(count($items));
        $bar->setFormat(
            " %current%/%max% [%bar%] %percent:3s%%\n"
            .' <info>%message%</info>'
        );
        $bar->setMessage('Starting...');
        $bar->start();

        foreach ($items as $item) {
            [$name, $theme] = $item;

            if (isset($item[3])) {
                $themeName = $theme?->name() ?? '—';
                $bar->setMessage("{$name} | {$themeName}");
                $this->streamFailure($bar, $name, $themeName, $item[3]);
                $rows[] = [$name, $themeName, 'Failed', $item[3]];
                $failed++;
                $bar->advance();

                continue;
            }

            /** @var ThemeFusion|null $theme */
            if ($theme === null) {
                $bar->setMessage("{$name} — No theme set");
                $this->streamFailure($bar, $name, '—', 'No theme set (empty themes())');
                $rows[] = [$name, '—', 'Failed', 'No theme set (empty themes())'];
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
                $rows[] = [$name, $themeName, 'Failed', $e->getMessage()];
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
                    $rows[] = [$name, $themeName, 'Updated', $resources ? 'with resources' : 'without resources'];
                    $updated++;
                } else {
                    FusionReport::templates()->upload($path, $name, $themeName, $description, $resources);
                    $rows[] = [$name, $themeName, 'Registered', $resources ? 'with resources' : 'without resources'];
                    $created++;
                }
            } catch (Throwable $e) {
                $this->streamFailure($bar, $name, $themeName, $e->getMessage());
                $rows[] = [$name, $themeName, 'Failed', $e->getMessage()];
                $failed++;
            }

            $bar->advance();
        }

        $bar->setMessage('Done.');
        $bar->finish();
        $this->newLine(2);

        $this->table(['Template', 'Theme', 'Action', 'Detail'], $rows);
        $this->info("Registered: {$created} | Updated: {$updated} | Ignored: {$skipped} | Failures: {$failed}");

        $result = $failed > 0 ? self::FAILURE : self::SUCCESS;

        if ($prune) {
            $this->newLine();
            $result = max($result, $this->prune($declared, $existing));
        }

        return $result;
    }

    /**
     * Remove do servidor os templates `(name + theme)` que o config não declara.
     *
     * Compara com a listagem feita no início do comando — templates que o próprio
     * sync acabou de cadastrar não estão nela, então nunca são candidatos.
     *
     * Um nome cuja definition tem `themes()` vazio fica de fora: é erro de config,
     * o nome continua sendo do app, e apagar os temas dele destruiria justamente
     * o que o sync não conseguiu ler.
     *
     * Interativo, lista os candidatos e pede confirmação (padrão: não). Sem
     * terminal (CI), o próprio `--prune` é o consentimento.
     *
     * @param  array<int, array{0: string, 1: ThemeFusion|null}>  $declared
     * @param  Collection<string, TemplateResource>  $existing
     */
    private function prune(array $declared, Collection $existing): int
    {
        $declaredKeys = collect($declared)
            ->filter(fn ($item) => $item[1] !== null)
            ->mapWithKeys(fn ($item) => [$this->key($item[0], $item[1]->name()) => true]);

        $unreadNames = collect($declared)
            ->filter(fn ($item) => $item[1] === null)
            ->mapWithKeys(fn ($item) => [$item[0] => true]);

        $orphans = $existing->reject(fn (TemplateResource $t, string $key) => $declaredKeys->has($key)
            || $unreadNames->has($t->name()));

        if ($orphans->isEmpty()) {
            $this->info('Prune: no template on the server outside the config.');

            return self::SUCCESS;
        }

        $this->warn("Prune: {$orphans->count()} template(s) on the server are not defined in config:");
        $this->table(['Template', 'Theme', 'Id'], $orphans
            ->map(fn (TemplateResource $t) => [$t->name(), $t->theme(), $t->id()])
            ->values()
            ->all());

        if ($this->input->isInteractive() && ! confirm(
            label: "Remove these {$orphans->count()} template(s) from the server? This cannot be undone.",
            default: false,
        )) {
            $this->info('Prune cancelled — nothing removed.');

            return self::SUCCESS;
        }

        $removed = $failed = 0;

        foreach ($orphans as $template) {
            try {
                FusionReport::templates()->delete($template->id());
                $removed++;
            } catch (Throwable $e) {
                $this->output->writeln("  <fg=red>✗</> {$template->name()} | {$template->theme()} — {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Removed: {$removed} | Failures: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Avisa antes de enviar quando os templates novos passam do limite do
     * plano. Só avisa: o servidor recusa com 422 o que exceder, e o resto do
     * sync continua valendo.
     *
     * @param  array<int, array{0: string, 1: ThemeFusion|null}>  $items
     */
    private function warnAboutQuota(array $items, Collection $existing): void
    {
        $new = count(array_filter($items, fn ($item) => $item[1] !== null
            && ! $existing->has($this->key($item[0], $item[1]->name()))));

        if ($new === 0) {
            return;
        }

        ['limit' => $limit, 'count' => $count] = FusionReport::templates()->quota();

        if ($limit !== null && $count + $new > $limit) {
            $this->warn(sprintf(
                'Plan template limit: %d of %d used, %d new to register — %d will be refused by the server.',
                $count, $limit, $new, $count + $new - $limit,
            ));
        }
    }

    /**
     * Erros do próprio config, achados antes de qualquer envio.
     *
     * O sync consulta o servidor uma vez só, no início. Um par (name + theme)
     * declarado duas vezes parecia novo nas duas: o 2º upload voltava 422, e no
     * --force o último sobrescrevia o primeiro sem aviso. Um `name()` repetido
     * entre definitions faz ainda o callback cair no `onWebhookReceived()` da
     * primeira (ver FusionReportClient::definitionFor()). Todas as ocorrências
     * falham — não há como saber qual o app quis.
     *
     * @param  array<int, array{0: string, 1: ThemeFusion|null, 2: class-string}>  $items
     * @return array<int, string> índice do item => motivo
     */
    private function configErrors(array $items, array $sources): array
    {
        $sourcesByName = [];
        $countByKey = [];

        foreach ($items as $index => [$name, $theme, $class]) {
            $sourcesByName[$name][$sources[$index]] = $class;

            if ($theme !== null) {
                $key = $this->key($name, $theme->name());
                $countByKey[$key] = ($countByKey[$key] ?? 0) + 1;
            }
        }

        $errors = [];

        foreach ($items as $index => [$name, $theme, $class]) {
            // Um name() por chave do config, seja outra classe com o mesmo
            // nome, seja a mesma classe registrada duas vezes. Resolvido aqui,
            // o tema repetido abaixo só sobra para o themes() de uma classe.
            $declaredIn = $sourcesByName[$name];

            $errors[$index] = match (true) {
                trim($name) === '' => "Report name() is empty in {$class}",
                count($declaredIn) > 1 => "Report [{$name}] declared in ".count($declaredIn).' config keys: '
                    .implode(', ', array_map(
                        fn ($key, $owner) => "{$key} ({$owner})",
                        array_keys($declaredIn),
                        $declaredIn,
                    )),
                $theme !== null && trim($theme->name()) === '' => "Theme name is empty in {$class}",
                $theme !== null && $countByKey[$this->key($name, $theme->name())] > 1 => "Theme [{$theme->name()}] declared {$countByKey[$this->key($name, $theme->name())]} times in {$class}",
                default => null,
            };
        }

        return array_filter($errors, fn ($error) => $error !== null);
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
            ->filter(fn ($item) => $item[1] !== null
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
            return $registered->map(fn () => true);
        }

        $selected = multiselect(
            label: 'Which templates to update?',
            options: $registered->all(),
            hint: 'Space checks, Enter confirms. Unmarked are ignored.',
            scroll: 15,
        );

        return collect($selected)->mapWithKeys(fn ($key) => [$key => true]);
    }

    private function key(string $name, string $theme): string
    {
        return $name.'|'.$theme;
    }
}
