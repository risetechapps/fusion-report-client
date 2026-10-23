<?php

namespace RiseTechApps\FusionReport\Datasources;

use RiseTechApps\FusionReport\Datasources\Contracts\Datasource;

class ProfileDatasource implements Datasource
{
    private function __construct(private readonly string $profileId) {}

    public static function use(string $profileId): static
    {
        return new static($profileId);
    }

    public function type(): string
    {
        return 'profile';
    }

    public function config(): array
    {
        return ['profile' => $this->profileId];
    }
}
