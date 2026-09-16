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
        Schema::create('publications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft')->index();
            $table->foreignUlid('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoked_reason')->nullable();
            $table->text('reject_reason')->nullable();
            $table->date('embargo_until')->nullable()->index();
            $table->boolean('privacy_cleared')->default(false);
            $table->string('download_policy', 20)->default('preview_only');
            $table->string('credit_line', 500)->nullable();
            $table->string('permalink_slug', 120)->nullable()->unique();
            $table->unsignedInteger('published_lock_version')->nullable();
            $table->timestampsTz();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE publications ADD CONSTRAINT publications_status_check CHECK (status IN ('draft', 'in_review', 'published', 'revoked'))");
            DB::statement("ALTER TABLE publications ADD CONSTRAINT publications_download_policy_check CHECK (download_policy IN ('none', 'preview_only'))");
        }
    }

    public function down(): void
    {
        // Publication decisions are auditable archival history; migrations are forward-only.
    }
};
