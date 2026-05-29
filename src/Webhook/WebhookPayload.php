<?php

namespace RiseTechApps\FusionReport\Webhook;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RiseTechApps\FusionReport\Exceptions\WebhookSignatureException;

class WebhookPayload implements \JsonSerializable
{
    private function __construct(private readonly array $data) {}

    public static function fromRequest(Request $request, ?string $secret = null): static
    {
        if ($secret !== null) {
            static::verifySignature($request, $secret);
        }

        return new static($request->json()->all());
    }

    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    public function uuid(): string
    {
        return $this->data['id'];
    }

    public function status(): string
    {
        return $this->data['status'];
    }

    public function isSuccess(): bool
    {
        return $this->status() === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status() === 'failed';
    }

    public function templateName(): ?string
    {
        return $this->data['template_name'] ?? null;
    }

    public function frpId(): ?string
    {
        return $this->data['frp_id'] ?? null;
    }

    public function error(): ?string
    {
        return $this->data['error_message'] ?? null;
    }

    /** @return Collection<int, WebhookDownload> */
    public function downloads(): Collection
    {
        return collect($this->data['files'] ?? [])
            ->map(fn(array $d) => new WebhookDownload($d));
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function verifySignature(Request $request, string $secret): void
    {
        $signature = $request->header('X-Webhook-Signature');

        if (! $signature) {
            throw new WebhookSignatureException('Missing X-Webhook-Signature header.');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            throw new WebhookSignatureException(
                "Invalid webhook signature. Received: [{$signature}] Expected: [{$expected}]"
            );
        }
    }
}
