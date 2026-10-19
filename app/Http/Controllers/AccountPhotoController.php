<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AccountPhotoController extends Controller
{
    public function __invoke(User $user): StreamedResponse
    {
        Gate::authorize('viewPhoto', $user);
        abort_unless(AccountPhotos::ownsPath($user, $user->avatar_path), 404);
        $disk = Storage::disk('avatars');
        abort_unless($disk->exists($user->avatar_path), 404);
        $mime = $disk->mimeType($user->avatar_path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return $disk->response($user->avatar_path, 'profile-photo.'.pathinfo($user->avatar_path, PATHINFO_EXTENSION), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Vary' => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
