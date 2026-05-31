<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password
            && ! $request->routeIs('password.setup', 'password.setup.update', 'logout')
        ) {
            return redirect()->route('password.setup');
        }

        return $next($request);
    }
}
