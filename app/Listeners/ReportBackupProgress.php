<?php

namespace App\Listeners;

use App\Services\BackupProgress;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\BackupZipWasCreated;
use Spatie\Backup\Events\DumpingDatabase;

/**
 * Turns spatie's backup:run steps into progress for the backup page.
 */
class ReportBackupProgress
{
    public function handle($event)
    {
        if ($event instanceof DumpingDatabase) {
            BackupProgress::set(10, 'Exporting the database...');
        } elseif ($event instanceof BackupManifestWasCreated) {
            BackupProgress::set(35, 'Compressing files into a zip...');
        } elseif ($event instanceof BackupZipWasCreated) {
            BackupProgress::set(60, 'Saving the backup...');
        } elseif ($event instanceof BackupWasSuccessful) {
            BackupProgress::set(70, 'Backup created.');
        }
    }
}
