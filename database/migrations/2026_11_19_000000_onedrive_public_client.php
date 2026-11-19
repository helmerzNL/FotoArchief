<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * OneDrive now connects with a code through one shared, public app registration
     * (ADR-0023 in App-Guidance). A connection made with an installation's own
     * confidential registration (client ID plus secret) cannot be refreshed without
     * that secret, so it is reset: the administrator connects again once. The list of
     * already imported files stays, so nothing is imported twice.
     */
    public function up(): void
    {
        $connection = DB::table('onedrive_connections')->where('id', 1)->first();
        if ($connection === null || ($connection->client_id === null && $connection->client_secret === null && $connection->refresh_token === null)) {
            return;
        }

        DB::transaction(function (): void {
            DB::table('onedrive_connections')->where('id', 1)->update([
                'client_id' => null,
                'client_secret' => null,
                'refresh_token' => null,
                'access_token' => null,
                'access_token_expires_at' => null,
                'account_name' => null,
                'drive_id' => null,
                'connected_by_user_id' => null,
                'connected_at' => null,
                'folder_item_id' => null,
                'folder_name' => null,
                'folder_path' => null,
                'delta_link' => null,
                'resume_link' => null,
                'resume_offset' => 0,
                'updated_at' => now(),
            ]);
            DB::table('onedrive_connection_audits')->insert(['user_id' => null, 'action' => 'reset_for_public_client', 'created_at' => now()]);
        });
    }

    public function down(): void
    {
        // The reset cannot be undone: the old secret and tokens are gone on purpose.
    }
};
