<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        // 'App\Events\Event' => [
        //     'App\Listeners\EventListener',
        // ],
        \App\Events\TransactionPaymentAdded::class => [
            \App\Listeners\AddAccountTransaction::class,
        ],

        \App\Events\TransactionPaymentUpdated::class => [
            \App\Listeners\UpdateAccountTransaction::class,
        ],

        \App\Events\TransactionPaymentDeleted::class => [
            \App\Listeners\DeleteAccountTransaction::class,
        ],

        \Spatie\Backup\Events\DumpingDatabase::class => [
            \App\Listeners\ReportBackupProgress::class,
        ],

        \Spatie\Backup\Events\BackupManifestWasCreated::class => [
            \App\Listeners\ReportBackupProgress::class,
        ],

        \Spatie\Backup\Events\BackupZipWasCreated::class => [
            \App\Listeners\ReportBackupProgress::class,
        ],

        \Spatie\Backup\Events\BackupWasSuccessful::class => [
            \App\Listeners\ReportBackupProgress::class,
            \App\Listeners\UploadBackupToGoogleDrive::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {

        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
