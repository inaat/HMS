<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Guards for the order-booker sync API (config/mobile_sync.php).
 *
 *   mobile.sync:sync   - local PC -> cloud calls; needs header X-Sync-Key
 *   mobile.sync:mobile - booker app calls; needs "Authorization: Bearer <token>" from /api/mobile/login
 *   mobile.sync:login  - only checks this copy is the cloud
 *
 * Every mode answers 404 unless this copy serves the phones: MOBILE_SYNC_ROLE=cloud (post office for a shop PC) or
 * single (cloud-only business: this one server is the POS and the bookers' server).
 */
class MobileSync
{
    public function handle(Request $request, Closure $next, string $mode = 'mobile')
    {
        if (! in_array(config('mobile_sync.role'), ['cloud', 'single'], true)) {
            abort(404);
        }

        if ($mode === 'sync') {
            $key = (string) config('mobile_sync.sync_key');
            if (strlen($key) < 20 || ! hash_equals($key, (string) $request->header('X-Sync-Key'))) {
                return response()->json(['message' => 'Invalid sync key'], 401);
            }
        } elseif ($mode === 'mobile') {
            $user = $this->userFromToken((string) $request->bearerToken());
            if (empty($user)) {
                return response()->json(['message' => 'Login expired or blocked. Please log in again.'], 401);
            }
            $request->attributes->set('mb_user', $user);
        }

        return $next($request);
    }

    private function userFromToken(string $token)
    {
        if ($token === '') {
            return null;
        }

        $row = DB::table('mb_tokens as t')
            ->join('mb_users as u', 'u.id', '=', 't.user_id')
            ->where('t.token_hash', hash('sha256', $token))
            ->where('u.allow_login', 1)
            ->where(function ($q) {
                $q->whereNull('t.expires_at')->orWhere('t.expires_at', '>', now());
            })
            ->select('u.id', 'u.username', 'u.name', 'u.code', 't.id as token_id', 't.last_used_at')
            ->first();

        if (! empty($row) && (empty($row->last_used_at) || strtotime($row->last_used_at) < time() - 60)) {
            DB::table('mb_tokens')->where('id', $row->token_id)->update(['last_used_at' => now()]);
        }

        return $row;
    }
}
