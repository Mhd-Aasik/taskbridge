<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;

class EnsureVerifiedEmail extends EnsureEmailIsVerified
{
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        return config('auth.require_verified_email')
            ? parent::handle($request, $next, $redirectToRoute)
            : $next($request);
    }
}
