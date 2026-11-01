<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** API presensi perkuliahan tidak tersedia selama fiturnya nonaktif. */
class EnsureTeachingEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('attendance.sessions.enabled'), 404);

        return $next($request);
    }
}
