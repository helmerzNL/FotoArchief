<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_operations', function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('operation_type', 50);
            $table->unsignedInteger('affected_count')->default(0);
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_operations');
    }
};
