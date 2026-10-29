<?php

namespace RiseTechApps\FusionReport\Exceptions;

use RuntimeException;

class FusionReportException extends RuntimeException
{
    /**
     * Resposta 2xx sem o `id` do recurso.
     *
     * Acontece quando algo no caminho responde no lugar do servidor — página de
     * manutenção, proxy ou CDN devolvendo 200 — ou quando o envelope muda. Sem
     * esta checagem o acesso direto virava "Undefined array key" e um 500 sem
     * relação aparente com o Fusion Report.
     */
    public static function missingId(string $resource, array $data): self
    {
        $keys = array_keys($data);

        return new self(sprintf(
            'Fusion Report Server response has no %s id (received keys: %s).',
            $resource,
            $keys === [] ? 'none' : implode(', ', $keys),
        ));
    }
}
