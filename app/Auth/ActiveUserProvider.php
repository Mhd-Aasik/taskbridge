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
        $current = $this->retrieveById($user->getAuthIdentifier());
        if (! $current || ! $current->isActive()) {
            throw ValidationException::withMessages(['email' => AccountAccess::UNAVAILABLE]);
        }

        return true;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        $user = parent::retrieveByToken($identifier, $token);

        return $user?->isActive() ? $user : null;
    }
}
