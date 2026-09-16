<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_suggestions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('publication_id')->nullable()->constrained('publications')->nullOnDelete();
            $table->string('suggestion_type', 20);
            $table->text('message');
            $table->string('submitter_name', 200)->nullable();
            $table->string('submitter_email', 255)->nullable();
            $table->string('ip_hash', 64);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignUlid('moderator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('moderator_note')->nullable();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampsTz();
            $table->index(['asset_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE asset_suggestions ADD CONSTRAINT asset_suggestions_type_check CHECK (suggestion_type IN ('correction', 'identification'))");
            DB::statement("ALTER TABLE asset_suggestions ADD CONSTRAINT asset_suggestions_status_check CHECK (status IN ('pending', 'accepted', 'rejected'))");
        }
    }

    public function down(): void
    {
        // Suggestions are auditable visitor input; migrations are forward-only.
    }
};
