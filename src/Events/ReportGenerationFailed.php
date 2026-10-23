<?php

namespace RiseTechApps\FusionReport\Events;

use Illuminate\Database\Eloquent\Model;
use RiseTechApps\FusionReport\Resources\GenerationResource;

/**
 * A geração falhou no servidor.
 *
 * ATENÇÃO: `$error` costuma vir null no caminho assíncrono. O servidor mantém
 * `error_message` em `$hidden`, então o payload do webhook não traz o motivo —
 * ver "Campos sempre vazios" no README.
 */
final class ReportGenerationFailed
{
    public function __construct(
        public readonly GenerationResource $generation,
        public readonly array $context = [],
        public readonly ?Model $owner = null,
        public readonly ?string $error = null,
    ) {}
}
