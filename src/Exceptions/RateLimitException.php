<?php

namespace RiseTechApps\FusionReport\Exceptions;

/**
 * HTTP 429 — cota de requisições do plano esgotada.
 *
 * O servidor responde este status pelo middleware de cobrança por API key.
 * Quando o cabeçalho `Retry-After` vem na resposta, ele é exposto em
 * `retryAfter()` (em segundos).
 */
class RateLimitException extends FusionReportException
{
    public function __construct(string $message, private readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    /** Segundos até poder tentar de novo, quando o servidor informa. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
