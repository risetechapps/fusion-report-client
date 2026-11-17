<?php

namespace RiseTechApps\FusionReport\Webhook;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RiseTechApps\FusionReport\Exceptions\MalformedWebhookException;
use RiseTechApps\FusionReport\Exceptions\WebhookSignatureException;

class WebhookPayload implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $data  a geração (o `data` do envelope)
     */
    private function __construct(
        private readonly array $data,
        private readonly ?string $deliveryId = null,
        private readonly ?string $event = null,
    ) {}

    /**
     * Lê o corpo nos dois formatos que o servidor já mandou:
     *
     * - servidor 1.3.0+: o envelope dos webhooks do pacote de API key,
     *   `{id, event, created_at, data}`, com a geração em `data`;
     * - servidor até 1.2.x: a geração na raiz do corpo.
     */
    public static function fromRequest(Request $request, ?string $secret = null): static
    {
        if ($secret !== null) {
            static::verifySignature($request, $secret);
        }

        $body = $request->json()->all();
        $deliveryId = trim((string) $request->header('X-Webhook-Id'));
        $event = null;

        if (static::isEnvelope($body)) {
            $event = $body['event'];
            $deliveryId = $deliveryId !== '' ? $deliveryId : trim((string) ($body['id'] ?? ''));
            $body = $body['data'];
        }

        return new static($body, $deliveryId === '' ? null : $deliveryId, $event);
    }

    /** @param  array<string, mixed>  $data  a geração, como toArray() devolve */
    public static function fromArray(array $data, ?string $deliveryId = null, ?string $event = null): static
    {
        return new static($data, $deliveryId, $event);
    }

    /** O envelope do pacote: `event` texto e `data` objeto. */
    private static function isEnvelope(array $body): bool
    {
        return is_string($body['event'] ?? null) && is_array($body['data'] ?? null);
    }

    /**
     * Id da entrega (`X-Webhook-Id`). O servidor repete o mesmo id nas novas
     * tentativas e no "Reenviar" do painel; null quando o header não veio.
     */
    public function deliveryId(): ?string
    {
        return $this->deliveryId;
    }

    /** Chave de cache que marca a entrega como já processada. */
    public function deliveryKey(): ?string
    {
        return $this->deliveryId === null ? null : "fusion-report:webhook-delivery:{$this->deliveryId}";
    }

    /**
     * Tipo do evento: `report.completed`, `report.failed` ou `webhook.test`.
     * Null nos callbacks do servidor até 1.2.x, que não usavam o envelope.
     */
    public function event(): ?string
    {
        if ($this->event !== null) {
            return $this->event;
        }

        $event = $this->data['event'] ?? null;

        return is_string($event) ? $event : null;
    }

    /**
     * Teste do painel do servidor: `webhook.test` (Webhooks → "Testar uma URL")
     * ou, no servidor 1.2.x, a geração com `status: "test"`.
     */
    public function isTest(): bool
    {
        return $this->event() === 'webhook.test' || ($this->data['status'] ?? null) === 'test';
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
            ->map(fn (array $d) => new WebhookDownload($d));
    }

    /** A geração (o `data` do envelope), sem o envelope. */
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

    /**
     * O servidor assina `"{X-Webhook-Timestamp}.{corpo bruto}"`. Com o
     * timestamp dentro do HMAC, uma entrega capturada não pode ser reenviada
     * depois da janela de `webhook.tolerance`.
     */
    private static function verifySignature(Request $request, string $secret): void
    {
        $signature = trim((string) $request->header('X-Webhook-Signature'));

        if ($signature === '') {
            throw WebhookSignatureException::missing();
        }

        $timestamp = trim((string) $request->header('X-Webhook-Timestamp'));

        if (! ctype_digit($timestamp)) {
            throw WebhookSignatureException::missingTimestamp();
        }

        // Aceita o hex sem o prefixo `sha256=` e em maiúsculas: é o mesmo HMAC,
        // então tolerar a forma não enfraquece a verificação.
        $signature = strtolower($signature);
        if (! str_starts_with($signature, 'sha256=')) {
            $signature = 'sha256='.$signature;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        // Nunca inclua $expected na mensagem: é a assinatura válida para o corpo
        // recebido, então devolvê-la transformaria a exceção em oráculo de
        // assinatura para quem conseguisse observá-la (log, debug, response).
        if (! hash_equals($expected, $signature)) {
            throw WebhookSignatureException::invalid();
        }

        // Conferido depois da assinatura: só um timestamp autêntico diz algo
        // sobre a idade da entrega.
        $tolerance = (int) config('fusion-report.webhook.tolerance', 300);

        if ($tolerance > 0 && abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            throw WebhookSignatureException::expired();
        }
    }
}
