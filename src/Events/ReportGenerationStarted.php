<?php

namespace RiseTechApps\FusionReport\Events;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\FusionReport\Resources\GenerationResource;

/**
 * O servidor aceitou a requisição de geração.
 *
 * Disparado tanto na geração síncrona quanto na assíncrona, logo após o
 * registro em `fusion_report_generations`. Na síncrona é seguido, no mesmo
 * ciclo, por ReportGenerationCompleted ou ReportGenerationFailed.
 */
final class ReportGenerationStarted
{
    public function __construct(
        public readonly GenerationResource $generation,
        public readonly array $context = [],
        public readonly ?Model $owner = null,
    ) {}
}
