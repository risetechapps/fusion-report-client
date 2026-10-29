<?php

namespace RiseTechApps\FusionReport\Tests\Fixtures;

/** Definition com defaultParams(), para testar a precedência dos params. */
class ParamsReport extends TestReport
{
    public function name(): string
    {
        return 'com_params';
    }

    public function defaultParams(): array
    {
        return ['ANO' => 2025, 'EMPRESA' => 'Definition'];
    }
}
