<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountPhotoController extends Controller
{
    public function __invoke(User $user): StreamedResponse
    {
        Gate::authorize('viewPhoto', $user);

        return app(AccountPhotos::class)->response($user);
    }
}
