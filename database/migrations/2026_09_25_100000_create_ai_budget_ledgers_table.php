<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_budget_ledgers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // period_key is the calendar month the reservation belongs to
            // (UTC "Y-m"), so budget resets naturally at month boundaries
            // without a scheduled job.
            $table->string('period_key', 7);
            $table->string('provider_kind', 20);
            $table->string('capability', 20);
            $table->unsignedInteger('cents_limit');
            $table->unsignedInteger('cents_reserved')->default(0);
            $table->unsignedInteger('cents_consumed')->default(0);
            $table->timestamps();

            $table->unique(['period_key', 'provider_kind', 'capability'], 'ai_budget_ledgers_period_provider_capability_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_budget_ledgers');
    }
};
