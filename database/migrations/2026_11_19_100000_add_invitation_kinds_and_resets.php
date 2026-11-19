<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitation links follow App-Guidance shared/guidelines/user-management.md: an invitation is
 * either a new account (kind "new") or a reset for an existing user (kind "reset", with user_id),
 * and using one deletes it. Rows that were already accepted are deleted here for the same reason.
 * users.has_password records whether the account can sign in with a password at all (an account
 * that chose a passkey on its invitation has an unusable random hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->string('kind', 10)->default('new')->index();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        });

        DB::table('user_invitations')->whereNotNull('accepted_at')->delete();

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('has_password')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('has_password');
        });
        Schema::table('user_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
