<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;

class AiProviderConfig extends Model
{
    protected $table = 'ai_provider_configs';

    protected $guarded = [];

    protected $hidden = ['api_key'];

    protected $casts = [
        'enabled' => 'boolean',
        'api_key' => 'encrypted',
        'cost_cents_per_image' => 'integer',
        'cost_cents_per_embedding' => 'integer',
        'monthly_budget_cents' => 'integer',
    ];

    /** @throws DecryptException */
    public function decryptedApiKey(): ?string
    {
        $apiKey = $this->getAttribute('api_key');

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }
}
