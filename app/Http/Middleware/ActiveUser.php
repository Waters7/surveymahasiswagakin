<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->active, 403);
        if ($request->user()->must_change_password && ! $request->routeIs('profile*', 'logout')) {
            return redirect('/profile')->with('status', 'Ganti password awal sebelum melanjutkan.');
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
