<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worklists', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('worklist_type', 50)->default('custom');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active');
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('filter_criteria')->nullable();
            $table->timestampsTz();
        });

        Schema::create('worklist_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('worklist_id')->constrained('worklists')->cascadeOnDelete();
            $table->foreignUlid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->string('status', 30)->default('pending');
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->unique(['worklist_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worklist_items');
        Schema::dropIfExists('worklists');
    }
};
