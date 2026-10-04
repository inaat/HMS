<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Progress of a backup / "send to Drive" action that the backup page polls
 * (GET backup/progress/{token}) while the action's own request is running.
 *
 * The token is the one the page sent with the action; while none is active
 * (scheduled backup:run, console) every call here is a no-op.
 */
class BackupProgress
{
    protected static $token;

    public static function start($token)
    {
        static::$token = static::validToken($token) ? $token : null;
        static::set(0, 'Starting...');
    }

    public static function set($percent, $text, $state = 'working')
    {
        if (empty(static::$token)) {
            return;
        }

        Cache::put(static::key(static::$token), [
            'percent' => (int) round($percent),
            'text' => $text,
            'state' => $state,
        ], now()->addHour());
    }

    public static function finish($ok, $text)
    {
        static::set($ok ? 100 : 0, $text, $ok ? 'done' : 'failed');
        static::$token = null;
    }

    public static function get($token)
    {
        $default = ['percent' => 0, 'text' => 'Starting...', 'state' => 'working'];

        return static::validToken($token) ? Cache::get(static::key($token), $default) : $default;
    }

    protected static function validToken($token)
    {
        return is_string($token) && preg_match('/^[a-z0-9]{8,64}$/', $token);
    }

    protected static function key($token)
    {
        return 'backup_progress_'.$token;
    }
}
