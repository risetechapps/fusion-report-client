<?php

namespace RiseTechApps\FusionReport\Resources;

use RiseTechApps\FusionReport\Exceptions\FusionReportException;

class TemplateResource implements \JsonSerializable
{
    public function __construct(private readonly array $data) {}

    /** @throws FusionReportException quando a resposta veio sem `id` */
    public function id(): string
    {
        $id = $this->data['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw FusionReportException::missingId('template', $this->data);
        }

        return $id;
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

    /** Sempre '': `file_path` está em `$hidden` no servidor. */
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

    /** Sempre null: `resources_path` está em `$hidden` no servidor. Use hasResources(). */
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
