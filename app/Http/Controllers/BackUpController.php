<?php

namespace App\Http\Controllers;

use App\Listeners\UploadBackupToGoogleDrive;
use App\Services\BackupProgress;
use App\Services\GoogleDriveService;
use App\Utils\Util;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Log;
use Storage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
class BackUpController extends Controller
{
    /**
     * All Utils instance.
     */
    protected $commonUtil;

    public function __construct(Util $commonUtil)
    {
        $this->commonUtil = $commonUtil;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // $token = DB::table('dropbox_tokens')->first();
        // if ($token) {
            // if (Carbon::now()->greaterThan($token->expires_at)) {
                // // If expired, redirect to home
                // return redirect('/dropbox/authorize');
            // }
        // }
        // if (! auth()->user()->can('backup')) {
            // abort(403, 'Unauthorized action.');
        // }

        $disk = Storage::disk(config('backup.backup.destination.disks')[0]);

        $files = $disk->files(config('backup.backup.name'));

        $backups = [];
        // make an array of backup files, with their filesize and creation date
        foreach ($files as $k => $f) {
            // only take the zip files into account
            if (substr($f, -4) == '.zip' && $disk->exists($f)) {
                $backups[] = [
                    'file_path' => $f,
                    'file_name' => str_replace(str_replace('\\', '/', config('backup.backup.name')).'/', '', $f),
                    'file_size' => $disk->size($f),
                    'last_modified' => $disk->lastModified($f),
                ];
            }
        }
        // reverse the backups, so the newest one would be on top
        $backups = array_reverse($backups);

        $cron_job_command = $this->commonUtil->getCronJobCommand();
        
        // $backup_clean_cron_job_command = $this->commonUtil->getBackupCleanCronJobCommand();

        $google_drive = \App\GoogleDriveSetting::current();
        $drive_ready = app(GoogleDriveService::class)->isConfigured($google_drive);
        $drive_files = [];
        $drive_error = null;
        if ($google_drive->isConnected()) {
            try {
                $drive_files = app(GoogleDriveService::class)->listFiles($google_drive);
            } catch (\Exception $e) {
                $drive_error = $e->getMessage();
            }
        }

        return view('backup.index')
            ->with(compact('backups', 'cron_job_command', 'google_drive', 'drive_ready', 'drive_files', 'drive_error'));
    }

    /**
     * Runs a backup over ajax so the page can show progress
     * (polled from progress() with the same token).
     */
    public function run(Request $request)
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        $notAllowed = $this->commonUtil->notAllowedInDemo();
        if (! empty($notAllowed)) {
            return response()->json(['ok' => false, 'message' => 'Feature disabled in demo!!']);
        }

        @set_time_limit(0);

        $to_drive = (bool) $request->input('drive');
        BackupProgress::start($request->input('progress'));
        BackupProgress::set(3, 'Preparing backup...');

        UploadBackupToGoogleDrive::$enabled = $to_drive;
        UploadBackupToGoogleDrive::$result = null;

        try {
            $exit_code = Artisan::call('backup:run');
            $output = Artisan::output();
            Log::info("Backpack\BackupManager -- new backup started from admin interface \r\n".$output);

            if ($exit_code != 0) {
                $message = 'Backup failed: '.trim(collect(explode("\n", trim($output)))->last());
                BackupProgress::finish(false, $message);

                return response()->json(['ok' => false, 'message' => $message]);
            }

            BackupProgress::set(97, 'Removing old backups on this server...');
            Artisan::call('backup:clean');
        } catch (\Exception $e) {
            BackupProgress::finish(false, 'Backup failed: '.$e->getMessage());

            return response()->json(['ok' => false, 'message' => 'Backup failed: '.$e->getMessage()]);
        }

        $ok = true;
        $message = 'Backup created.';
        if ($to_drive) {
            $ok = UploadBackupToGoogleDrive::$result === true;
            $message = $ok
                ? 'Backup created and sent to Google Drive.'
                : 'Backup created, but sending it to Google Drive failed: '.(\App\GoogleDriveSetting::current()->last_upload_status ?: 'Google Drive is not connected.');
        }

        BackupProgress::finish($ok, $message);

        return response()->json(['ok' => $ok, 'message' => $message]);
    }

    public function progress($token)
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        return response()->json(BackupProgress::get($token));
    }

    /**
     * Create a resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            //Disable in demo
            $notAllowed = $this->commonUtil->notAllowedInDemo();
            if (! empty($notAllowed)) {
                return $notAllowed;
            }

            // start the backup process
            Artisan::call('backup:run');
            $output = Artisan::output();
            Artisan::call('backup:clean');
            $output = Artisan::output();

            // log the results
            Log::info("Backpack\BackupManager -- new backup started from admin interface \r\n".$output);
            
            $output = ['success' => 1,
                'msg' => __('lang_v1.success'),
            ];
        } catch (Exception $e) {
            $output = ['success' => 0,
                'msg' => $e->getMessage(),
            ];
        }

        return back()->with('status', $output);
    }

    /**
     * Downloads a backup zip file.
     *
     * TODO: make it work no matter the flysystem driver (S3 Bucket, etc).
     */
    public function download($file_name)
    {
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        //Disable in demo
        if (config('app.env') == 'demo') {
            $output = ['success' => 0,
                'msg' => 'Feature disabled in demo!!',
            ];

            return back()->with('status', $output);
        }

        $file = config('backup.backup.name').'/'.$file_name;
        $disk = Storage::disk(config('backup.backup.destination.disks')[0]);
        if ($disk->exists($file)) {
            $fs = Storage::disk(config('backup.backup.destination.disks')[0])->getDriver();
            $stream = $fs->readStream($file);
            //var_dump($fs->size($file));exit;

            return \Response::stream(function () use ($stream) {
                fpassthru($stream);
            }, 200, [
                'Content-Type' => $fs->mimeType($file),
                //'Content-Length' => $fs->getSize($file),
                'Content-disposition' => 'attachment; filename="'.basename($file).'"',
            ]);
        } else {
            abort(404, "The backup file doesn't exist.");
        }
    }

    /**
     * Deletes a backup file.
     */
    public function delete($file_name)
    {
        
        if (! auth()->user()->can('backup')) {
            abort(403, 'Unauthorized action.');
        }

        //Disable in demo
        if (config('app.env') == 'demo') {
            $output = ['success' => 0,
                'msg' => 'Feature disabled in demo!!',
            ];

            return back()->with('status', $output);
        }

        $disk = Storage::disk(config('backup.backup.destination.disks')[0]);
        if ($disk->exists(config('backup.backup.name').'/'.$file_name)) {
            $disk->delete(config('backup.backup.name').'/'.$file_name);

            return redirect()->back();
        } else {
            abort(404, "The backup file doesn't exist.");
        }
    }
}
