<?php

namespace RiseTechApps\FusionReport\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * Cota do plano lida dos headers `X-Quota-*` de uma resposta de geração.
 *
 * O servidor 1.1 manda os headers nas quatro rotas que descontam da cota,
 * inclusive no 429 de cota esgotada. Plano ilimitado não manda `Limit` nem
 * `Remaining`, então os dois ficam null.
 */
class Quota implements \JsonSerializable
{
    public function __construct(
        private readonly ?int $limit,
        private readonly ?int $remaining,
        private readonly int $used,
        private readonly ?CarbonImmutable $resetsAt,
    ) {}

    /** Null quando a resposta não traz `X-Quota-Used` (servidor anterior à 1.1). */
    public static function fromResponse(Response $response): ?static
    {
        $used = $response->header('X-Quota-Used');

        if (! is_numeric($used)) {
            return null;
        }

        $int = fn (string $name) => is_numeric($value = $response->header($name)) ? (int) $value : null;
        $reset = $int('X-Quota-Reset');

        return new static(
            limit: $int('X-Quota-Limit'),
            remaining: $int('X-Quota-Remaining'),
            used: (int) $used,
            resetsAt: $reset !== null ? CarbonImmutable::createFromTimestamp($reset) : null,
        );
    }

    /** Limite do período; null em plano ilimitado. */
    public function limit(): ?int
    {
        return $this->limit;
    }

    /** Quanto ainda resta no período; null em plano ilimitado. */
    public function remaining(): ?int
    {
        return $this->remaining;
    }

    public function used(): int
    {
        return $this->used;
    }

    /** Fim do período, quando a cota volta. */
    public function resetsAt(): ?CarbonImmutable
    {
        return $this->resetsAt;
    }

    public function isUnlimited(): bool
    {
        return $this->limit === null;
    }

    /** @return array{limit: ?int, remaining: ?int, used: int, resets_at: ?string} */
    public function toArray(): array
    {
        return [
            'limit' => $this->limit,
            'remaining' => $this->remaining,
            'used' => $this->used,
            'resets_at' => $this->resetsAt?->toIso8601String(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
