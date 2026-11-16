<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The single OneDrive connection of this installation (row id 1).
 *
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $refresh_token
 * @property string|null $access_token
 * @property Carbon|null $access_token_expires_at
 * @property string|null $account_name
 * @property string|null $drive_id
 * @property string|null $folder_item_id
 * @property string|null $folder_name
 * @property string|null $folder_path
 * @property string|null $delta_link
 * @property string|null $resume_link
 * @property int $resume_offset
 * @property string|null $connected_by_user_id
 * @property Carbon|null $connected_at
 */
class OneDriveConnection extends Model
{
    protected $table = 'onedrive_connections';

    protected $guarded = [];

    public $incrementing = false;

    protected $hidden = ['client_secret', 'refresh_token', 'access_token', 'delta_link', 'resume_link'];

    protected $casts = [
        'client_secret' => 'encrypted',
        'refresh_token' => 'encrypted',
        'access_token' => 'encrypted',
        'access_token_expires_at' => 'datetime',
        'resume_offset' => 'integer',
        'connected_at' => 'datetime',
    ];

    public static function current(): self
    {
        return self::query()->firstOrCreate(['id' => 1]);
    }

    public function hasApp(): bool
    {
        return is_string($this->client_id) && $this->client_id !== ''
            && is_string($this->client_secret) && $this->client_secret !== '';
    }

    public function isConnected(): bool
    {
        return $this->hasApp() && is_string($this->refresh_token) && $this->refresh_token !== '';
    }

    public function hasFolder(): bool
    {
        return is_string($this->folder_item_id) && $this->folder_item_id !== '';
    }
}
