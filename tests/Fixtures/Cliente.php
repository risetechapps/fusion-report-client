<?php

namespace RiseTechApps\FusionReport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RiseTechApps\FusionReport\Concerns\HasFusionReportGenerations;

/** Dono de gerações, sem tabela: os testes só leem `fusion_report_generations`. */
class Cliente extends Model
{
    use HasFusionReportGenerations;
    use HasUuids;

    protected $table = 'clientes';
}
