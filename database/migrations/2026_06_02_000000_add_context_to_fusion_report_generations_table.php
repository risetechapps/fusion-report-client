<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('fusion_report_generations', 'context')) {
            return;
        }

        Schema::table('fusion_report_generations', function (Blueprint $table) {
            $table->json('context')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fusion_report_generations', 'context')) {
            return;
        }

        Schema::table('fusion_report_generations', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
