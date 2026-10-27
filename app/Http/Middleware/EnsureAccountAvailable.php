<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        $actor = Auth::user();
        if (! $actor) {
            // Signed temporary uploads/previews are still private authenticated surfaces.
            abort_if($request->routeIs('livewire.upload-file', 'livewire.preview-file'), 401);

            return $next($request);
        }
        $current = User::query()->select(['id', 'role', 'email_verified_at', 'suspended_at', 'access_version'])->find($actor->id);
        $stamp = $request->session()->get(AccountAccess::SESSION_KEY, ['id' => $actor->id, 'version' => 0]);
        if (is_array($stamp) && ($stamp['id'] ?? null) !== $actor->id) {
            $stamp = ['id' => $actor->id, 'version' => 0];
        }
        $valid = $current && $current->isActive() && is_array($stamp)
            && ($stamp['id'] ?? null) === $current->id && ($stamp['version'] ?? null) === $current->access_version
            && (int) $actor->access_version === $current->access_version;
        if (! $valid) {
            $message = $current && ! $current->isActive() ? AccountAccess::UNAVAILABLE : AccountAccess::EXPIRED;
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->routeIs('logout')) {
                return $next($request);
            }
            abort_if($request->expectsJson() || $request->hasHeader('X-Livewire') || $request->routeIs('livewire.upload-file', 'livewire.preview-file'), 403, $message);

            return redirect()->route('login')->withErrors(['email' => $message]);
        }
        // Persist the baseline stamp for pre-feature sessions; never upgrade an old stamp.
        $request->session()->put(AccountAccess::SESSION_KEY, $stamp);
        $actor->role = $current->role;
        $actor->email_verified_at = $current->email_verified_at;
        $request->attributes->set('taskbridge.available_actor', $current);

        if (config('auth.require_verified_email') && ! $current->hasVerifiedEmail()) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson() || $request->hasHeader('X-Livewire') || $request->routeIs('livewire.upload-file', 'livewire.preview-file')) {
                abort(403, 'Verify your email address before accessing TaskBridge.');
            }
            if ($request->routeIs('verification.notice', 'verification.verify', 'verification.send', 'verification.confirm', 'verification.complete', 'logout')) {
                return $next($request);
            }

            return redirect()->route('verification.notice');
        }

        return $next($request);
    }
}
