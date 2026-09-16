<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_ocr_texts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->foreignUlid('asset_file_id')->constrained('asset_files')->cascadeOnDelete();
            $table->longText('extracted_text')->nullable();
            $table->longText('edited_text')->nullable();
            $table->boolean('is_edited')->default(false)->index();
            $table->float('confidence')->nullable();
            $table->string('language', 50)->default('nld+eng');
            $table->string('engine_version', 50)->nullable();
            $table->string('status', 30)->default('queued')->index();
            $table->text('error_message')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['asset_file_id']);
            $table->index(['asset_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_ocr_texts');
    }
};
