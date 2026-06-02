<?php

namespace RiseTechApps\FusionReport\Webhook;

class WebhookDownload implements \JsonSerializable
{
    public function __construct(private readonly array $data) {}

    public function format(): string
    {
        return $this->data['format'] ?? '';
    }

    public function filename(): string
    {
        return $this->data['filename'] ?? '';
    }

    public function url(): string
    {
        return $this->data['download_url'] ?? '';
    }

    public function expiresAt(): ?string
    {
        return $this->data['expires_at'] ?? null;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
