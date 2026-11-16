<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One installation connects at most one personal OneDrive (row id 1). Secret and
        // tokens are encrypted by the model casts; see ADR-0022 in App-Guidance.
        Schema::create('onedrive_connections', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('client_id', 100)->nullable();
            $table->text('client_secret')->nullable();
            $table->text('refresh_token')->nullable();
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->string('account_name', 255)->nullable();
            $table->string('drive_id', 100)->nullable();
            $table->string('folder_item_id', 200)->nullable();
            $table->string('folder_name', 255)->nullable();
            $table->string('folder_path', 1000)->nullable();
            // After a completed import: changes since then. During an import that is not
            // finished (stopped, failed, cancelled or at the run's chunk limit): the page
            // and position the next import continues from.
            $table->text('delta_link')->nullable();
            $table->text('resume_link')->nullable();
            $table->unsignedInteger('resume_offset')->default(0);
            $table->foreignUlid('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });

        // Every OneDrive file that was offered to ingest once, so a top-up or a retried
        // run never imports it twice. A rejected file is remembered too: retrying it
        // cannot help.
        Schema::create('onedrive_imported_items', function (Blueprint $table): void {
            $table->id();
            $table->string('item_id', 200)->unique();
            $table->string('ctag', 200)->nullable();
            $table->foreignUlid('quarantine_upload_id')->nullable()->constrained('quarantine_uploads')->nullOnDelete();
            $table->string('outcome', 20);
            $table->string('original_path', 1000);
            $table->timestamp('created_at')->nullable();
        });

        // Folder paths by item ID. Delta responses on a personal OneDrive may omit an
        // item's path, so a file's folder is resolved through its parent ID once.
        Schema::create('onedrive_folders', function (Blueprint $table): void {
            $table->string('item_id', 200)->primary();
            $table->string('path', 1000);
            $table->timestamps();
        });

        Schema::create('onedrive_connection_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onedrive_connection_audits');
        Schema::dropIfExists('onedrive_folders');
        Schema::dropIfExists('onedrive_imported_items');
        Schema::dropIfExists('onedrive_connections');
    }
};
