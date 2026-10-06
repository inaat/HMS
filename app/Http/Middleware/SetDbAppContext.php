<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tags the database connection with who is doing what (user + page), so the deletion_audit triggers can record
 * where a delete came from.
 */
class SetDbAppContext
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('HEAD')) {
            try {
                $user = auth()->user();
                $context = 'app: user '.($user ? $user->id.' '.$user->username : 'guest')
                    .' | '.$request->method().' '.$request->path()
                    .' | '.optional($request->route())->getActionName()
                    .' | ip '.$request->ip();
                DB::statement('SET @app_context = ?', [substr($context, 0, 500)]);
            } catch (\Throwable $e) {
                //never block a request because of the audit tag
            }
        }

        return $next($request);
    }
}
