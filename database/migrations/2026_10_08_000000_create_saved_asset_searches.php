<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_asset_searches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->jsonb('filters');
            $table->timestampsTz();
        });
        Schema::create('worklist_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('worklist_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->jsonb('details');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Catalogue receipts require a forward-only migration.');
    }
};
