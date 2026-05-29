<?php

namespace RiseTechApps\FusionReport\Datasources\Contracts;

interface Datasource
{
    public function type(): string;

    public function config(): array;
}
