<?php

namespace App\Services;

use App\GoogleDriveSetting;
use Illuminate\Support\Facades\Http;

/**
 * Talks to Google OAuth + Drive v3 over plain REST (no google/apiclient needed)
 * to keep a copy of each backup zip in the connected account's Drive.
 */
class GoogleDriveService
{
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
    const ABOUT_URL = 'https://www.googleapis.com/drive/v3/about';
    const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
    const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    // drive.file only sees files this app created, so pruning can never touch
    // anything else in the user's Drive. Asked for alone: with extra scopes
    // (openid/email) Google shows per-scope checkboxes and Drive can be left out.
    const SCOPES = 'https://www.googleapis.com/auth/drive.file';

    const FOLDER_MIME = 'application/vnd.google-apps.folder';

    // resumable upload chunks must be a multiple of 256 KB
    const CHUNK_SIZE = 8 * 1024 * 1024;

    protected function clientId(GoogleDriveSetting $setting)
    {
        return $setting->effectiveClientId();
    }

    protected function clientSecret(GoogleDriveSetting $setting)
    {
        return $setting->effectiveClientSecret();
    }

    public function redirectUri()
    {
        return config('services.google_drive.redirect') ?: url('oauth/google/callback');
    }

    public function isConfigured(GoogleDriveSetting $setting)
    {
        return $setting->hasCredentials();
    }

    /**
     * The state Google echoes back. GOOGLE_REDIRECT_URI may be a shared relay
     * (fatooranow.com/oauth/google/callback) that reads redirect_url from it and
     * forwards ?code / ?token on to this app's own callback.
     */
    public function makeState()
    {
        $back = url('oauth/google/callback');

        // the keys the relay reads to send the browser back here
        return base64_encode(json_encode([
            'is_database' => true,
            'is_url' => $back,
            'redirect_url' => $back,
        ]));
    }

    public function authUrl(GoogleDriveSetting $setting, $state)
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId($setting),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            // offline + consent so Google always hands back a refresh_token
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function handleCallback(GoogleDriveSetting $setting, $code)
    {
        $token = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId($setting),
            'client_secret' => $this->clientSecret($setting),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        $this->handleToken($setting, $token);
    }

    /**
     * Stores a token Google issued - either swapped here from a code, or
     * handed over already swapped by the redirect relay (?token=base64 json).
     */
    public function handleToken(GoogleDriveSetting $setting, array $token)
    {
        if (empty($token['access_token'])) {
            throw new \Exception('Google did not return an access token.');
        }
        if (empty($token['refresh_token']) && empty($setting->refresh_token)) {
            throw new \Exception('Google did not return a refresh token, so scheduled uploads could not keep working. Remove the app from your Google account permissions and connect again.');
        }

        if (! empty($token['scope']) && ! static::hasDriveScope($token['scope'])) {
            throw new \Exception('Google Drive access was not granted. Connect again and, on the Google screen, tick the box that allows access to Google Drive files.');
        }

        $email = Http::withToken($token['access_token'])->timeout(30)
            ->get(self::ABOUT_URL, ['fields' => 'user(emailAddress)'])->json('user.emailAddress');

        $setting->update([
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? $setting->refresh_token,
            'expires_at' => now()->addSeconds(($token['expires_in'] ?? 3600) - 60),
            'scope' => $token['scope'] ?? self::SCOPES,
            'connected_email' => $email,
            // a different account can't see the previous account's folder
            'folder_id' => null,
        ]);
    }

    public function disconnect(GoogleDriveSetting $setting)
    {
        if ($setting->refresh_token) {
            // best effort - the local tokens are dropped either way
            try {
                Http::asForm()->timeout(15)->post(self::REVOKE_URL, ['token' => $setting->refresh_token]);
            } catch (\Exception $e) {
            }
        }

        $setting->update([
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'scope' => null,
            'connected_email' => null,
            'folder_id' => null,
        ]);
    }

    public static function hasDriveScope($scope)
    {
        return in_array(self::SCOPES, preg_split('/\s+/', (string) $scope));
    }

    protected function accessToken(GoogleDriveSetting $setting)
    {
        if ($setting->scope && ! static::hasDriveScope($setting->scope)) {
            throw new \Exception('This Google connection has no Drive access. Click "Disconnect", then "Connect Google Drive" and allow access to Google Drive files.');
        }

        if ($setting->access_token && $setting->expires_at && $setting->expires_at->isFuture()) {
            return $setting->access_token;
        }

        if (! $setting->isConnected()) {
            throw new \Exception('Google Drive is not connected.');
        }

        $token = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'client_id' => $this->clientId($setting),
            'client_secret' => $this->clientSecret($setting),
            'refresh_token' => $setting->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($token->failed()) {
            // invalid_grant = user revoked access from their Google account
            throw new \Exception('Google Drive token refresh failed: '.($token->json('error_description') ?: $token->body()));
        }

        $setting->update([
            'access_token' => $token->json('access_token'),
            'expires_at' => now()->addSeconds(($token->json('expires_in') ?? 3600) - 60),
        ]);

        return $setting->access_token;
    }

    protected function api(GoogleDriveSetting $setting)
    {
        return Http::withToken($this->accessToken($setting))->timeout(60);
    }

    /**
     * Returns the id of the backups folder, creating it again if it was
     * deleted or trashed in Drive.
     */
    protected function folderId(GoogleDriveSetting $setting)
    {
        if ($setting->folder_id) {
            $folder = $this->api($setting)->get(self::FILES_URL.'/'.$setting->folder_id, ['fields' => 'id,trashed']);
            if ($folder->successful() && ! $folder->json('trashed')) {
                return $setting->folder_id;
            }
        }

        $folder = $this->api($setting)->post(self::FILES_URL.'?fields=id', [
            'name' => config('app.name', 'POS').' Backups',
            'mimeType' => self::FOLDER_MIME,
        ])->throw();

        $setting->update(['folder_id' => $folder->json('id')]);

        return $setting->folder_id;
    }

    /**
     * Uploads a stream to the backups folder with a resumable upload, so large
     * zips go up in chunks instead of being loaded into memory at once.
     *
     * @param  resource  $stream
     * @param  callable|null  $onProgress  called with the bytes sent so far after each chunk
     * @return string Drive file id
     */
    public function upload(GoogleDriveSetting $setting, $stream, $size, $fileName, $onProgress = null)
    {
        $session = $this->api($setting)
            ->withHeaders([
                'X-Upload-Content-Type' => 'application/zip',
                'X-Upload-Content-Length' => $size,
            ])
            ->post(self::UPLOAD_URL.'?uploadType=resumable&fields=id', [
                'name' => $fileName,
                'parents' => [$this->folderId($setting)],
            ])->throw();

        $location = $session->header('Location');
        $offset = 0;
        // smaller chunks while a progress bar is watching, so it moves smoothly
        $chunk_size = $onProgress ? 1024 * 1024 : self::CHUNK_SIZE;

        while (true) {
            $chunk = stream_get_contents($stream, $chunk_size);
            $length = strlen($chunk);
            $range = $length > 0
                ? 'bytes '.$offset.'-'.($offset + $length - 1).'/'.$size
                : 'bytes */'.$size;

            $response = Http::withToken($this->accessToken($setting))
                ->timeout(300)
                // Drive answers 308 "Resume Incomplete" between chunks - not a redirect
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['Content-Range' => $range])
                ->withBody($chunk, 'application/zip')
                ->put($location);

            if ($response->status() == 308) {
                $offset += $length;
                if ($length == 0) {
                    throw new \Exception('Google Drive upload stalled at byte '.$offset);
                }
                if ($onProgress) {
                    $onProgress($offset);
                }

                continue;
            }

            $response->throw();

            return $response->json('id');
        }
    }

    /**
     * Deletes all but the newest $keep files in the backups folder.
     */
    public function prune(GoogleDriveSetting $setting, $keep)
    {
        if ($keep < 1 || ! $setting->folder_id) {
            return 0;
        }

        $files = $this->api($setting)->get(self::FILES_URL, [
            'q' => "'".$setting->folder_id."' in parents and trashed = false and mimeType != '".self::FOLDER_MIME."'",
            'orderBy' => 'createdTime desc',
            'fields' => 'files(id,name)',
            'pageSize' => 1000,
        ])->throw()->json('files', []);

        $deleted = 0;
        foreach (array_slice($files, $keep) as $file) {
            if ($this->api($setting)->delete(self::FILES_URL.'/'.$file['id'])->successful()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Backup zips in the Drive folder, newest first.
     */
    public function listFiles(GoogleDriveSetting $setting)
    {
        if (! $setting->folder_id) {
            return [];
        }

        $files = $this->api($setting)->get(self::FILES_URL, [
            'q' => "'".$setting->folder_id."' in parents and trashed = false and mimeType != '".self::FOLDER_MIME."'",
            'orderBy' => 'createdTime desc',
            'fields' => 'files(id,name,size,createdTime,webViewLink)',
            'pageSize' => 1000,
        ])->throw()->json('files', []);

        return array_map(function ($file) {
            return [
                'id' => $file['id'],
                'name' => $file['name'],
                'size' => (int) ($file['size'] ?? 0),
                'date' => \Carbon\Carbon::parse($file['createdTime'])->setTimezone(config('app.timezone')),
                'link' => $file['webViewLink'] ?? null,
            ];
        }, $files);
    }

    /**
     * Metadata of a file, only if it sits in the backups folder - so a crafted
     * id can't reach anything else this app may have created in Drive.
     */
    public function backupFile(GoogleDriveSetting $setting, $fileId)
    {
        $file = $this->api($setting)->get(self::FILES_URL.'/'.rawurlencode($fileId), ['fields' => 'id,name,size,mimeType,parents,trashed']);

        if ($file->failed() || $file->json('trashed') || ! in_array($setting->folder_id, $file->json('parents', []))) {
            return null;
        }

        return $file->json();
    }

    /**
     * @return \Psr\Http\Message\StreamInterface
     */
    public function downloadStream(GoogleDriveSetting $setting, $fileId)
    {
        return Http::withToken($this->accessToken($setting))
            ->timeout(0)
            ->withOptions(['stream' => true])
            ->get(self::FILES_URL.'/'.rawurlencode($fileId), ['alt' => 'media'])
            ->throw()
            ->toPsrResponse()
            ->getBody();
    }

    public function deleteFile(GoogleDriveSetting $setting, $fileId)
    {
        $this->api($setting)->delete(self::FILES_URL.'/'.rawurlencode($fileId))->throw();
    }

    /**
     * Uploads one backup, prunes old copies and records the outcome on the
     * settings row. Never throws - a Drive problem must not fail the backup.
     *
     * $from / $to: the slice of the page's progress bar the upload fills.
     */
    public function uploadBackup($stream, $size, $fileName, $from = 5, $to = 95)
    {
        $setting = GoogleDriveSetting::current();

        if (! $setting->isConnected()) {
            return false;
        }

        try {
            $mb = function ($bytes) {
                return number_format($bytes / 1048576, 1);
            };

            BackupProgress::set($from, 'Uploading to Google Drive...');
            $this->upload($setting, $stream, $size, $fileName, function ($sent) use ($from, $to, $size, $mb) {
                BackupProgress::set($from + ($to - $from) * $sent / max($size, 1),
                    'Uploading to Google Drive... '.$mb($sent).' of '.$mb($size).' MB');
            });

            BackupProgress::set($to, 'Removing old copies from Google Drive...');
            $this->prune($setting, $setting->keep_last);

            $setting->update([
                'last_upload_at' => now(),
                'last_upload_status' => 'OK: '.$fileName,
            ]);

            return true;
        } catch (\Exception $e) {
            \Log::emergency('Google Drive backup upload: '.$e->getMessage());

            // Google's own message instead of Laravel's truncated "HTTP request returned..." dump
            $message = $e instanceof \Illuminate\Http\Client\RequestException
                ? ($e->response->json('error.message') ?: $e->getMessage())
                : $e->getMessage();

            $setting->update([
                'last_upload_at' => now(),
                'last_upload_status' => 'Failed: '.$message,
            ]);

            return false;
        }
    }
}
