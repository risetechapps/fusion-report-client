<?php

namespace RiseTechApps\FusionReport\Exceptions;

use RiseTechApps\FusionReport\Resources\Quota;

/**
 * HTTP 429 — cota do plano esgotada ou limite de taxa atingido.
 *
 * `retryAfter()` vem do header `Retry-After` (limite de taxa) ou, com a cota
 * esgotada, do fim do período em `X-Quota-Reset`. `quota()` traz a cota dos
 * headers `X-Quota-*` quando o servidor (1.1+) os manda.
 */
class RateLimitException extends FusionReportException
{
    public function __construct(
        string $message,
        private readonly ?int $retryAfter = null,
        private readonly ?Quota $quota = null,
    ) {
        parent::__construct($message);
    }

    /** Segundos até poder tentar de novo, quando dá para saber. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** Cota no momento da recusa; null sem os headers `X-Quota-*`. */
    public function quota(): ?Quota
    {
        return $this->quota;
    }

    /** true quando a recusa é pela cota do período, não pelo limite de taxa. */
    public function isQuotaExhausted(): bool
    {
        return $this->quota?->remaining() === 0;
    }
}
