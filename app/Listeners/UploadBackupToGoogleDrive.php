<?php

namespace App\Listeners;

use App\Services\GoogleDriveService;
use Spatie\Backup\Events\BackupWasSuccessful;

/**
 * Copies every new backup zip (manual "Add" button or the scheduled
 * backup:run) to the connected Google Drive account.
 */
class UploadBackupToGoogleDrive
{
    // scheduled backups always go to Drive; the backup page turns this off for
    // its plain "Back up now" button
    public static $enabled = true;

    // null = no upload attempted in this process, otherwise whether it worked
    public static $result = null;

    protected $googleDriveService;

    public function __construct(GoogleDriveService $googleDriveService)
    {
        $this->googleDriveService = $googleDriveService;
    }

    public function handle(BackupWasSuccessful $event)
    {
        if (! static::$enabled) {
            return;
        }

        $destination = $event->backupDestination;

        // the event fires once per backup disk; upload only the primary copy
        if ($destination->diskName() != config('backup.backup.destination.disks')[0]) {
            return;
        }

        $backup = $destination->newestBackup();
        if (empty($backup)) {
            return;
        }

        @set_time_limit(0);

        $stream = $backup->stream();
        try {
            static::$result = $this->googleDriveService->uploadBackup($stream, (int) $backup->sizeInBytes(), basename($backup->path()), 72, 95);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
