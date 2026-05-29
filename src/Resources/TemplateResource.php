<?php

namespace RiseTechApps\FusionReport\Resources;

class TemplateResource implements \JsonSerializable
{
    public function __construct(private readonly array $data) {}

    public function id(): string
    {
        return $this->data['id'];
    }

    public function name(): string
    {
        return $this->data['name'] ?? '';
    }

    public function theme(): string
    {
        return $this->data['theme'] ?? 'default';
    }

    public function description(): ?string
    {
        return $this->data['description'] ?? null;
    }

    public function originalFilename(): string
    {
        return $this->data['original_filename'] ?? '';
    }

    public function filePath(): string
    {
        return $this->data['file_path'] ?? '';
    }

    public function createdAt(): ?string
    {
        return $this->data['created_at'] ?? null;
    }

    public function updatedAt(): ?string
    {
        return $this->data['updated_at'] ?? null;
    }

    public function resourcesPath(): ?string
    {
        return $this->data['resources_path'] ?? null;
    }

    public function hasResources(): bool
    {
        return $this->data['has_resources'] ?? false;
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
