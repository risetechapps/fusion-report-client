<?php

namespace RiseTechApps\FusionReport\Events;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\FusionReport\Resources\GenerationResource;

/**
 * A geração terminou com sucesso e os arquivos estão disponíveis.
 *
 * Na geração síncrona vem da própria resposta; na assíncrona, do callback de
 * webhook. Use `$generation->files()` para acessar os downloads.
 */
final class ReportGenerationCompleted
{
    public function __construct(
        public readonly GenerationResource $generation,
        public readonly array $context = [],
        public readonly ?Model $owner = null,
    ) {}
}
