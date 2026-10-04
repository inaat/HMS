<?php
// app/Backup/Cleanup/KeepLatestBackups.php

namespace App\Backup\Cleanup;

use Spatie\Backup\Tasks\Cleanup\CleanupStrategy;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\BackupDestination\BackupDestination;

class KeepLatestBackups extends CleanupStrategy
{
    public function deleteOldBackups(BackupCollection $backups)
    {
        // Sort the backups by date in descending order
        $backups = $backups->sortByDesc('date');

        // Keep only the latest 5 backups
        $backupsToKeep = $backups->slice(0, 5);

        // Delete old backups except those to keep
        foreach ($backups as $backup) {
            if (!$backupsToKeep->contains($backup)) {
                $backup->delete();
            }
        }
    }

    /* public function deleteOldBackups(BackupCollection $backups)
    {
        // Sort backups by date in descending order to get the latest backup first
        $backups = $backups->sortByDesc('date');

        // Keep only the latest backup
        $backupsToKeep = $backups->first(); // Keep only the latest one

        // Delete old backups except the one to keep
        foreach ($backups as $backup) {
            if ($backup !== $backupsToKeep) {
                $backup->delete();
            }
        }
    }*/
}
