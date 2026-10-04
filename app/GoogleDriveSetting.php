<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row table holding the Google Drive connection used for backups.
 */
class GoogleDriveSetting extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'client_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
        'last_upload_at' => 'datetime',
        'keep_last' => 'integer',
    ];

    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    // Client ID saved on the backup page wins; blank = GOOGLE_DRIVE_CLIENT_ID from .env
    public function effectiveClientId()
    {
        return $this->client_id ?: config('services.google_drive.client_id');
    }

    public function effectiveClientSecret()
    {
        return $this->client_id ? $this->client_secret : config('services.google_drive.client_secret');
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->effectiveClientId()) && ! empty($this->effectiveClientSecret());
    }

    public function isConnected(): bool
    {
        return ! empty($this->refresh_token);
    }

    // connected, and the user actually ticked Drive access on Google's screen
    public function hasDriveAccess(): bool
    {
        return $this->isConnected()
            && (empty($this->scope) || \App\Services\GoogleDriveService::hasDriveScope($this->scope));
    }
}
