<?php

namespace RiseTechApps\FusionReport\Definitions;

class ReportProtection
{
    private function __construct(
        private readonly ?string $userPassword = null,
        private readonly ?string $ownerPassword = null,
    ) {}

    public static function password(string $password): static
    {
        return new static(userPassword: $password);
    }

    public static function withOwner(string $userPassword, string $ownerPassword): static
    {
        return new static(userPassword: $userPassword, ownerPassword: $ownerPassword);
    }

    public function toArray(): array
    {
        return array_filter([
            'protect_document'         => $this->userPassword,
            'protect_document_default' => $this->ownerPassword,
        ]);
    }
}
