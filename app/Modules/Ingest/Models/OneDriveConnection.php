<?php

declare(strict_types=1);

namespace App\Modules\Ingest\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The single OneDrive connection of this installation (row id 1).
 *
 * The app connects through the shared, public Vistora app registration
 * (config ingest.onedrive.client_id). client_id holds an administrator's own client ID
 * when one was entered; it wins over the shared one. There is no client secret: the
 * client_secret column of the first version is no longer read or written.
 *
 * @property string|null $client_id
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

    /** The client ID the app signs in with: the own one, else the shared Vistora app. */
    public function clientId(): string
    {
        return $this->usesCustomApp() ? (string) $this->client_id : (string) config('ingest.onedrive.client_id', '');
    }

    public function usesCustomApp(): bool
    {
        return is_string($this->client_id) && $this->client_id !== '';
    }

    public function hasApp(): bool
    {
        return $this->clientId() !== '';
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
