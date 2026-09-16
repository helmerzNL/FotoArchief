<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->timestampTz('deleted_at')->nullable()->index();
            $table->foreignUlid('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('deletion_reason')->nullable();
        });

        Schema::create('trash_purge_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('asset_id', 26)->nullable()->index();
            $table->string('accession_number', 100);
            $table->string('title', 1024);
            $table->foreignUlid('purged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->unsignedInteger('deleted_files_count')->default(0);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trash_purge_logs');
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by_user_id']);
            $table->dropColumn(['deleted_at', 'deleted_by_user_id', 'deletion_reason']);
        });
    }
};
