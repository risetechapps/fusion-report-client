<?php

namespace RiseTechApps\FusionReport\Exceptions;

/**
 * HTTP 5xx — falha do servidor, não do pedido.
 *
 * 502 indica que o renderizador (servidor Java) falhou ou não respondeu; 500,
 * erro inesperado no servidor. Antes da versão 1.0.0 oficial do servidor os dois casos
 * chegavam como 410 e viravam `ReportNotFoundException`.
 *
 * Tentar de novo pode resolver, mas cuidado com operações que criam estado e
 * consomem cota (`generate`, `async`, `upload`, `export`): o servidor cobra por
 * requisição recebida.
 */
class ServerException extends FusionReportException
{
    public function __construct(string $message, private readonly int $status = 500)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** O renderizador (servidor Java) falhou ou não respondeu. */
    public function isRendererFailure(): bool
    {
        return $this->status === 502;
    }
}
