<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fusion_report_generations', function (Blueprint $table) {
            $table->id();
            $table->string('generation_uuid')->unique();
            $table->string('frp_uuid')->nullable();
            $table->string('name')->nullable();
            $table->json('formats');
            $table->json('download_urls')->nullable();
            $table->string('status')->default('pending');
            $table->nullableUuidMorphs('loggable');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fusion_report_generations');
    }
};
