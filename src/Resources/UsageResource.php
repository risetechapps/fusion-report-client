<?php

namespace RiseTechApps\FusionReport\Resources;

use Carbon\CarbonImmutable;

/**
 * Consumo da conta, de `GET /api/v1/usage` (servidor 1.1+).
 *
 * A consulta não desconta da cota e responde mesmo com ela esgotada.
 */
class UsageResource implements \JsonSerializable
{
    public function __construct(private readonly array $data) {}

    public function planName(): ?string
    {
        return $this->data['plan']['name'] ?? null;
    }

    /** Status da assinatura no período: `active`, `grace`... */
    public function periodStatus(): ?string
    {
        return $this->data['period']['status'] ?? null;
    }

    public function periodStart(): ?CarbonImmutable
    {
        return $this->date($this->data['period']['start'] ?? null);
    }

    public function periodEnd(): ?CarbonImmutable
    {
        return $this->date($this->data['period']['end'] ?? null);
    }

    /** Limite de requisições do período; null em plano ilimitado. */
    public function limit(): ?int
    {
        return $this->int($this->data['requests']['limit'] ?? null);
    }

    public function used(): int
    {
        return $this->int($this->data['requests']['used'] ?? null) ?? 0;
    }

    /** Quanto ainda resta; null em plano ilimitado. */
    public function remaining(): ?int
    {
        return $this->int($this->data['requests']['remaining'] ?? null);
    }

    public function resetsAt(): ?CarbonImmutable
    {
        return $this->date($this->data['requests']['resets_at'] ?? null);
    }

    public function isUnlimited(): bool
    {
        return $this->limit() === null;
    }

    /** Requisições acima do limite já feitas no período (excedente). */
    public function overageRequests(): int
    {
        return $this->int($this->data['overage']['requests'] ?? null) ?? 0;
    }

    public function rateLimitPerMinute(): ?int
    {
        return $this->int($this->data['rate_limit']['per_minute'] ?? null);
    }

    /** Id da API key usada na consulta. */
    public function apiKeyId(): ?string
    {
        return $this->data['api_key']['id'] ?? null;
    }

    public function apiKeyExpiresAt(): ?CarbonImmutable
    {
        return $this->date($this->data['api_key']['expires_at'] ?? null);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value) : null;
    }
}
