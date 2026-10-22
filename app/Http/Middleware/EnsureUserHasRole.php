<?php

namespace App\Http\Middleware;

use App\Support\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = AccountAccess::account();

        abort_if(! $user || ! in_array($user->role, $roles, true), 403);

        return $next($request);
    }
}
