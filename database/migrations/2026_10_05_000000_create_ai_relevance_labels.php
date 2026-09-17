<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_relevance_labels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->string('deduplication_key', 64)->unique();
            $table->string('query', 200);
            $table->string('provider', 100);
            $table->string('model_space', 255);
            $table->string('collection_id', 26)->nullable();
            $table->unsignedTinyInteger('grade');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only migration: restore a verified backup instead.');
    }
};
