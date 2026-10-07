<?php

namespace App\Http\Middleware;

use Closure;

class CheckUserLogin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (($request->user()->user_type != 'user' || $request->user()->allow_login != 1) && request()->segment(1) != 'home') {
            abort(403, 'Unauthorized action.');
        }

        // Order bookers use only the mobile app; also ends a website session that was open before the role was given.
        $user = $request->user();
        if ($user->hasRole(\App\Services\MobileSync\LocalSnapshot::BOOKER_ROLE.'#'.$user->business_id)) {
            \Auth::logout();

            return redirect('/login')->with('status', ['success' => 0, 'msg' => 'This is an order booker account: use the mobile app, not the website.']);
        }

        return $next($request);
    }
}
