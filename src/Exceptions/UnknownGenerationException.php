<?php

namespace RiseTechApps\FusionReport\Exceptions;

/**
 * Callback de uma geração que ainda não existe em `fusion_report_generations`.
 *
 * Recuperável: o caso comum é o callback chegar antes de `generateAsync()`
 * gravar a linha (relatório rápido). A rota responde 503 para o servidor
 * reentregar, e o job é retentado pelo próprio backoff.
 */
class UnknownGenerationException extends FusionReportException {}
