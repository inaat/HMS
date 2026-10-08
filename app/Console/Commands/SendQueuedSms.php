<?php

namespace App\Console\Commands;

use App\Transaction;
use App\Utils\NotificationUtil;
use Illuminate\Console\Command;

/**
 * Sends one SMS that a sale queued (NotificationUtil::autoSendNotification writes the file and starts this command
 * in the background), so the cashier never waits for the SMS gateway.
 */
class SendQueuedSms extends Command
{
    protected $signature = 'notify:send-sms {file : JSON file under storage/app/sms-queue}';

    protected $description = 'Send a queued sale SMS in the background';

    public function handle()
    {
        $file = $this->argument('file');
        if (! is_file($file) || strpos(realpath($file), realpath(storage_path('app/sms-queue'))) !== 0) {
            return 1;
        }
        $job = json_decode((string) file_get_contents($file), true);
        @unlink($file);
        if (empty($job['data'])) {
            return 1;
        }

        try {
            $util = new NotificationUtil();
            $util->sendSms($job['data']);
            $transaction = Transaction::find($job['transaction_id'] ?? 0);
            if ($transaction) {
                $util->activityLog($transaction, 'sms_notification_sent', null, [], false, $job['business_id'] ?? null);
            }
        } catch (\Throwable $e) {
            \Log::emergency('File:'.$e->getFile().'Line:'.$e->getLine().'Message:'.$e->getMessage());

            return 1;
        }

        return 0;
    }
}
