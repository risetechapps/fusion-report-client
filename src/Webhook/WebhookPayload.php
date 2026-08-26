<?php

namespace RiseTechApps\FusionReport\Webhook;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RiseTechApps\FusionReport\Exceptions\MalformedWebhookException;
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
        return $this->requireString('id');
    }

    public function status(): string
    {
        return $this->requireString('status');
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

    /** Sempre null: `error_message` está em `$hidden` no servidor. */
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

    /**
     * Campo obrigatório do payload.
     *
     * `id` e `status` sustentam todo o resto do fluxo; sem eles o acesso direto
     * ao array virava "Undefined array key" e um 500 opaco.
     *
     * @throws MalformedWebhookException
     */
    private function requireString(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new MalformedWebhookException("Webhook payload missing required field [{$key}].");
        }

        return $value;
    }

    private static function verifySignature(Request $request, string $secret): void
    {
        $signature = $request->header('X-Webhook-Signature');

        if (! $signature) {
            throw new WebhookSignatureException('Missing X-Webhook-Signature header.');
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        // Nunca inclua $expected na mensagem: é a assinatura válida para o corpo
        // recebido, então devolvê-la transformaria a exceção em oráculo de
        // assinatura para quem conseguisse observá-la (log, debug, response).
        if (! hash_equals($expected, $signature)) {
            throw new WebhookSignatureException('Invalid webhook signature.');
        }
    }
}
