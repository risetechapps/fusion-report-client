<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fusion_report_generations', 'loggable_type')) {
            return;
        }

        Schema::table('fusion_report_generations', function (Blueprint $table) {
            $table->nullableUuidMorphs('loggable');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fusion_report_generations', 'loggable_type')) {
            return;
        }

        Schema::table('fusion_report_generations', function (Blueprint $table) {
            $table->dropMorphs('loggable');
        });
    }
};
