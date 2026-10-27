<?php

namespace App\Auth;

use App\Support\AccountAccess;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;

class ActiveUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
    {
        if (! parent::validateCredentials($user, $credentials)) {
            return false;
        }
        // A status explanation is safe only after the password has been verified.
        $current = parent::retrieveById($user->getAuthIdentifier());
        if (! $current || ! $current->isActive()) {
            throw ValidationException::withMessages(['email' => AccountAccess::UNAVAILABLE]);
        }
        if (config('auth.require_verified_email') && ! $current->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => AccountAccess::VERIFICATION_REQUIRED]);
        }

        return true;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $user?->isActive() && (! config('auth.require_verified_email') || $user->hasVerifiedEmail()) ? $user : null;
    }

    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);

        return $user && (! config('auth.require_verified_email') || $user->hasVerifiedEmail()) ? $user : null;
    }
}
