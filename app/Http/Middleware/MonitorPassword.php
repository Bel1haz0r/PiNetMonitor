<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Optional HTTP Basic protection, active only when MONITOR_PASSWORD is set. */
class MonitorPassword
{
    public function handle(Request $request, Closure $next)
    {
        $password = config('monitor.password');
        if ($password && ! hash_equals((string) $password, (string) $request->getPassword())) {
            return response('Authentication required.', 401, ['WWW-Authenticate' => 'Basic realm="PiNetMonitor"']);
        }

        return $next($request);
    }
}
