<?php

namespace App\Http\Controllers;

use App\GoogleDriveSetting;
use App\Services\BackupProgress;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Storage;

class GoogleDriveController extends Controller
{
    protected $googleDriveService;

    public function __construct(GoogleDriveService $googleDriveService)
    {
        $this->googleDriveService = $googleDriveService;
    }

    /**
     * Step 1: send the admin to Google's consent screen
     */
    public function connect(Request $request)
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        $setting = GoogleDriveSetting::current();
        if (! $this->googleDriveService->isConfigured($setting)) {
            return $this->back(0, 'Set GOOGLE_DRIVE_CLIENT_ID and GOOGLE_DRIVE_CLIENT_SECRET in .env first.');
        }

        return redirect()->away($this->googleDriveService->authUrl($setting, $this->googleDriveService->makeState()));
    }

    /**
     * Step 2: back from Google - directly, or through the GOOGLE_REDIRECT_URI
     * relay, which forwards either ?code (to swap here) or ?token (already swapped)
     */
    public function callback(Request $request)
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        if ($request->query('error')) {
            return $this->back(0, 'Google Drive was not connected: '.$request->query('error'));
        }

        try {
            $setting = GoogleDriveSetting::current();
            if ($request->filled('token')) {
                $this->googleDriveService->handleToken($setting, (array) json_decode(base64_decode((string) $request->query('token')), true));
            } elseif ($request->filled('code')) {
                $this->googleDriveService->handleCallback($setting, $request->query('code'));
            } else {
                return $this->back(0, 'Google Drive did not send anything back. Please try again.');
            }

            return $this->back(1, 'Google Drive connected: '.$setting->connected_email);
        } catch (\Exception $e) {
            \Log::emergency('Google Drive connect: '.$e->getMessage());

            return $this->back(0, 'Google Drive connection failed: '.$e->getMessage());
        }
    }

    public function disconnect()
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        $this->googleDriveService->disconnect(GoogleDriveSetting::current());

        return $this->back(1, 'Google Drive disconnected.');
    }

    /**
     * Optional Client ID / Secret override (blank Client ID = use .env) and
     * how many backups to keep in Drive.
     */
    public function saveCredentials(Request $request)
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        $data = $request->validate([
            'client_id' => (config('services.google_drive.client_id') ? 'nullable' : 'required').'|string|max:255',
            'client_secret' => 'nullable|string|max:255',
            'keep_last' => 'required|integer|min:1|max:1000',
        ]);

        $setting = GoogleDriveSetting::current();
        $before = $setting->effectiveClientId();
        $client_id = trim((string) ($data['client_id'] ?? ''));

        $setting->client_id = $client_id !== '' ? $client_id : null;
        if ($client_id === '') {
            // back to .env for both
            $setting->client_secret = null;
        } elseif (! empty($data['client_secret'])) {
            // blank = keep the saved secret
            $setting->client_secret = trim($data['client_secret']);
        }

        if (! $setting->hasCredentials()) {
            return $this->back(0, 'Please enter the Client Secret too.');
        }

        $setting->keep_last = $data['keep_last'];

        // a token belongs to the OAuth app that issued it
        $changed_app = $before !== $setting->effectiveClientId() || ! empty($data['client_secret']);
        if ($changed_app && $setting->refresh_token) {
            $setting->fill(['access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'connected_email' => null, 'folder_id' => null]);
        }
        $setting->save();

        return $this->back(1, $setting->isConnected() ? 'Google Drive settings saved.' : 'Saved. Now click "Connect Google Drive".');
    }

    /**
     * Sends one backup already on this server to Drive. Called over ajax by
     * the backup page, which polls BackupProgress with the same token.
     */
    public function send(Request $request, $file_name)
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        BackupProgress::start($request->input('progress'));

        $disk = Storage::disk(config('backup.backup.destination.disks')[0]);
        $file = config('backup.backup.name').'/'.basename($file_name);

        if (substr($file, -4) != '.zip' || ! $disk->exists($file)) {
            BackupProgress::finish(false, "The backup file doesn't exist.");

            return response()->json(['ok' => false, 'message' => "The backup file doesn't exist."]);
        }

        @set_time_limit(0);

        $stream = $disk->readStream($file);
        try {
            $ok = $this->googleDriveService->uploadBackup($stream, $disk->size($file), basename($file));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $message = $ok ? 'Sent to Google Drive: '.basename($file) : GoogleDriveSetting::current()->last_upload_status;
        BackupProgress::finish($ok, $message);

        return response()->json(['ok' => $ok, 'message' => $message]);
    }

    public function downloadFile($id)
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        $setting = GoogleDriveSetting::current();
        $file = $this->googleDriveService->backupFile($setting, $id);
        if (empty($file)) {
            abort(404, "The backup file doesn't exist.");
        }

        @set_time_limit(0);

        $body = $this->googleDriveService->downloadStream($setting, $id);

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(1024 * 1024);
                flush();
            }
        }, 200, [
            'Content-Type' => $file['mimeType'] ?? 'application/zip',
            'Content-Length' => $file['size'] ?? null,
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $file['name']).'"',
        ]);
    }

    public function deleteFile($id)
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $setting = GoogleDriveSetting::current();
            if (empty($this->googleDriveService->backupFile($setting, $id))) {
                return $this->back(0, "The backup file doesn't exist.");
            }

            $this->googleDriveService->deleteFile($setting, $id);

            return $this->back(1, 'Deleted from Google Drive.');
        } catch (\Exception $e) {
            return $this->back(0, 'Google Drive: '.$e->getMessage());
        }
    }

    protected function back($success, $msg)
    {
        return redirect()->action([BackUpController::class, 'index'])
            ->with('status', ['success' => $success, 'msg' => $msg]);
    }
}
