<?php

namespace App\Http\Controllers;

use App\Models\FreelancerProfile;
use App\Models\User;
use App\Services\AccountPhotos;
use App\Support\AccountAccess;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FreelancerProfilePhotoController extends Controller
{
    public function __invoke(int $profile): StreamedResponse
    {
        $viewer = AccountAccess::account();
        Gate::forUser($viewer)->authorize('browse', FreelancerProfile::class);
        $profile = FreelancerProfile::query()->visibleToClients()->where('share_account_photo', true)->find($profile, ['id', 'user_id']);
        abort_unless($profile, 404, '', ['Cache-Control' => 'private, no-store, max-age=0', 'Vary' => 'Cookie']);
        $owner = User::query()->whereKey($profile->user_id)->firstOrFail(['id', 'avatar_path']);

        return app(AccountPhotos::class)->response($owner);
    }
}
