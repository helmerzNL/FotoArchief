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
        Schema::create('operation_run_audit_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('operation_run_id')->constrained('operation_runs')->cascadeOnDelete();
            $table->foreignUlid('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('severity', 20);
            $table->text('message')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestampsTz();
            $table->index(['operation_run_id', 'created_at']);
        });

        DB::table('operation_runs')
            ->whereIn('operation_type', ['ai.analysis', 'ai.index'])
            ->where('status', 'completed')
            ->where('failed_items', '>', 0)
            ->update([
                'status' => 'failed',
                'error_message' => 'Deze AI-taak bevat mislukte items uit een eerdere versie. Probeer de taak opnieuw; het auditlog legt de concrete oorzaak vast.',
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_run_audit_events');
    }
};
