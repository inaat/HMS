<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * On the cloud copy (MOBILE_SYNC_ROLE=cloud) the web screens are view-only: mobile-sync:mirror on the local PC
 * overwrites this database with the local one, so anything saved here would be lost. Logging in and out still works.
 */
class CloudReadOnly
{
    const ALLOWED = ['login', 'logout', 'user/update-password', 'password/*'];

    public function handle(Request $request, Closure $next)
    {
        if (config('mobile_sync.role') !== 'cloud' || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']) || $request->is(self::ALLOWED)) {
            return $next($request);
        }

        $msg = 'This is the online view copy: it is read-only. Make changes in the shop PC; they appear here within a minute or two.';
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => false, 'msg' => $msg], 403);
        }

        return redirect()->back()->with('status', ['success' => 0, 'msg' => $msg]);
    }
}
