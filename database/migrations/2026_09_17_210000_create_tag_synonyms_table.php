<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tag_synonyms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tag_synonyms');
    }
};
