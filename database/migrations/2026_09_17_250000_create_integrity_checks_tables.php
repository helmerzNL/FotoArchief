<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrity_checks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUlid('asset_file_id')->constrained('asset_files')->cascadeOnDelete();
            $table->string('check_type', 50)->default('full')->index();
            $table->string('status', 40)->default('ok')->index();
            $table->char('expected_sha256', 64)->nullable();
            $table->char('actual_sha256', 64)->nullable();
            $table->jsonb('details')->nullable();
            $table->timestampTz('resolved_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrity_checks');
    }
};
