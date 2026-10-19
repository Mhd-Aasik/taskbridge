<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AccountPhotos
{
    /** @return array{contents: string, extension: string} */
    public function prepare(mixed $photo): array
    {
        Validator::make(['photo' => $photo], ['photo' => [
            'bail', 'required', 'file', 'max:2048', 'image',
            'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'extensions:jpg,jpeg,png,webp',
            'dimensions:max_width=4096,max_height=4096',
        ]])->validate();

        $contents = file_get_contents($photo->getRealPath());
        $information = @getimagesizefromstring($contents);
        $warning = false;
        set_error_handler(function () use (&$warning): bool {
            $warning = true;

            return true;
        });
        try {
            $image = imagecreatefromstring($contents);
        } catch (Throwable) {
            $image = false;
        } finally {
            restore_error_handler();
        }

        if (! $image || $warning) {
            if ($image) {
                imagedestroy($image);
            }
            throw ValidationException::withMessages(['photo' => 'Choose a valid, complete JPEG, PNG, or WebP image.']);
        }

        ob_start();
        try {
            $extension = match ($information[2]) {
                IMAGETYPE_JPEG => 'jpg',
                IMAGETYPE_PNG => 'png',
                IMAGETYPE_WEBP => 'webp',
            };
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $encoded = match ($extension) {
                'jpg' => imagejpeg($image, null, 90),
                'png' => imagepng($image, null, 6),
                'webp' => imagewebp($image, null, 85),
            };
            $normalised = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (! $encoded || $normalised === '') {
            throw new RuntimeException('The profile photo could not be encoded.');
        }

        return ['contents' => $normalised, 'extension' => $extension];
    }

    public function replace(User $account, mixed $photo): User
    {
        Gate::authorize('updatePhoto', $account);
        $prepared = $this->prepare($photo);
        $path = 'users/'.$account->getKey().'/'.Str::uuid().'.'.$prepared['extension'];
        $oldPath = null;

        try {
            if (! Storage::disk('avatars')->put($path, $prepared['contents'])) {
                throw new RuntimeException('The profile photo could not be stored.');
            }
            $account = DB::transaction(function () use ($account, $path, &$oldPath): User {
                $current = User::query()->lockForUpdate()->findOrFail($account->getKey());
                Gate::authorize('updatePhoto', $current);
                $oldPath = $current->avatar_path;
                $current->avatar_path = $path;
                if (! $current->saveOrFail()) {
                    throw new RuntimeException('The profile photo could not be saved.');
                }

                return $current;
            });
        } catch (Throwable $exception) {
            $this->deleteOwnedFile($account, $path);
            throw $exception;
        }

        DB::afterRollBack(fn () => $this->deleteOwnedFile($account, $path));
        DB::afterCommit(fn () => $this->deleteOwnedFile($account, $oldPath));

        return $account;
    }

    public function remove(User $account): User
    {
        Gate::authorize('updatePhoto', $account);
        $oldPath = null;
        $account = DB::transaction(function () use ($account, &$oldPath): User {
            $current = User::query()->lockForUpdate()->findOrFail($account->getKey());
            Gate::authorize('updatePhoto', $current);
            $oldPath = $current->avatar_path;
            $current->avatar_path = null;
            if (! $current->saveOrFail()) {
                throw new RuntimeException('The profile photo could not be removed.');
            }

            return $current;
        });
        DB::afterCommit(fn () => $this->deleteOwnedFile($account, $oldPath));

        return $account;
    }

    public static function ownsPath(User $account, ?string $path): bool
    {
        return $path !== null && preg_match('#\Ausers/'.preg_quote((string) $account->getKey(), '#').'/[a-f0-9-]{36}\.(jpg|png|webp)\z#D', $path) === 1;
    }

    public function deleteOwnedFile(User $account, ?string $path): void
    {
        if (! self::ownsPath($account, $path)) {
            return;
        }
        try {
            if (! Storage::disk('avatars')->delete($path)) {
                report(new RuntimeException('A superseded profile photo could not be deleted.'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
