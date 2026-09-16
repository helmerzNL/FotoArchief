<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_provider_configs')) {
            return;
        }

        Schema::create('ai_provider_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30)->unique();
            $table->boolean('enabled')->default(false);
            $table->text('api_key')->nullable();
            $table->string('vision_model', 160)->nullable();
            $table->string('embedding_model', 160)->nullable();
            $table->unsignedInteger('cost_cents_per_image')->default(0);
            $table->unsignedInteger('cost_cents_per_embedding')->default(0);
            $table->unsignedInteger('monthly_budget_cents')->default(0);
            $table->timestamps();
        });

        foreach ([
            'openai' => [
                'enabled' => (bool) env('AI_OPENAI_ENABLED', false),
                'api_key' => self::encryptedEnv('AI_OPENAI_API_KEY'),
                'vision_model' => env('AI_OPENAI_VISION_MODEL', 'gpt-4.1-mini'),
                'embedding_model' => null,
                'cost_cents_per_image' => (int) env('AI_OPENAI_COST_CENTS_PER_IMAGE', 0),
                'cost_cents_per_embedding' => 0,
                'monthly_budget_cents' => (int) env('AI_OPENAI_MONTHLY_BUDGET_CENTS', 0),
            ],
            'anthropic' => [
                'enabled' => (bool) env('AI_ANTHROPIC_ENABLED', false),
                'api_key' => self::encryptedEnv('AI_ANTHROPIC_API_KEY'),
                'vision_model' => env('AI_ANTHROPIC_VISION_MODEL', 'claude-sonnet-5'),
                'embedding_model' => null,
                'cost_cents_per_image' => (int) env('AI_ANTHROPIC_COST_CENTS_PER_IMAGE', 0),
                'cost_cents_per_embedding' => 0,
                'monthly_budget_cents' => (int) env('AI_ANTHROPIC_MONTHLY_BUDGET_CENTS', 0),
            ],
            'gemini' => [
                'enabled' => (bool) env('AI_GEMINI_ENABLED', false),
                'api_key' => self::encryptedEnv('AI_GEMINI_API_KEY'),
                'vision_model' => env('AI_GEMINI_VISION_MODEL', 'gemini-2.5-flash'),
                'embedding_model' => env('AI_GEMINI_EMBEDDING_MODEL', 'gemini-embedding-2'),
                'cost_cents_per_image' => (int) env('AI_GEMINI_COST_CENTS_PER_IMAGE', 0),
                'cost_cents_per_embedding' => (int) env('AI_GEMINI_COST_CENTS_PER_EMBEDDING', 0),
                'monthly_budget_cents' => (int) env('AI_GEMINI_MONTHLY_BUDGET_CENTS', 0),
            ],
            'openrouter' => [
                'enabled' => (bool) env('AI_OPENROUTER_ENABLED', false),
                'api_key' => self::encryptedEnv('AI_OPENROUTER_API_KEY'),
                'vision_model' => env('AI_OPENROUTER_VISION_MODEL'),
                'embedding_model' => env('AI_OPENROUTER_EMBEDDING_MODEL'),
                'cost_cents_per_image' => (int) env('AI_OPENROUTER_COST_CENTS_PER_IMAGE', 0),
                'cost_cents_per_embedding' => (int) env('AI_OPENROUTER_COST_CENTS_PER_EMBEDDING', 0),
                'monthly_budget_cents' => (int) env('AI_OPENROUTER_MONTHLY_BUDGET_CENTS', 0),
            ],
        ] as $provider => $values) {
            DB::table('ai_provider_configs')->insertOrIgnore([
                'provider' => $provider,
                ...$values,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

    }

    private static function encryptedEnv(string $key): ?string
    {
        $value = env($key);

        return is_string($value) && $value !== '' ? Crypt::encryptString($value) : null;
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_configs');
    }
};
