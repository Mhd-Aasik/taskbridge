<?php

namespace Tests\Feature\Settings;

use App\Models\FreelancerProfile;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Volt\Volt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AccountPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('avatars');
        Storage::fake('local');
        Storage::fake('public');
    }

    #[DataProvider('roles')]
    public function test_all_roles_can_upload_replace_remove_and_refresh_their_photo(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $profile = $role === User::ROLE_FREELANCER ? FreelancerProfile::factory()->create(['user_id' => $user->id, 'is_public' => true]) : null;
        $before = $user->getAttributes();
        $this->actingAs($user);

        Volt::test('settings.photo')->set('photo', $this->photo())
            ->assertSet('previewReady', true)->call('savePhoto')->assertHasNoErrors()
            ->assertSet('photo', null)->assertSee('Profile photo saved.')
            ->assertDispatched('account-photo-updated', fn ($event, $data) => $data['url'] === $user->fresh()->avatarUrl());

        $first = $user->fresh()->avatar_path;
        $firstUrl = $user->fresh()->avatarUrl();
        $this->assertMatchesRegularExpression('#^users/'.$user->id.'/[a-f0-9-]{36}\.png$#', $first);
        Storage::disk('avatars')->assertExists($first);
        Storage::disk('public')->assertMissing($first);
        $this->assertSame('image/png', Storage::disk('avatars')->mimeType($first));

        Volt::test('settings.photo')->set('photo', $this->photo('jpg'))->call('savePhoto')->assertHasNoErrors();
        $second = $user->fresh()->avatar_path;
        $this->assertNotSame($first, $second);
        $this->assertNotSame($firstUrl, $user->fresh()->avatarUrl());
        Storage::disk('avatars')->assertMissing($first);
        Storage::disk('avatars')->assertExists($second);

        $this->get(route($role.'.dashboard'))->assertOk()->assertSee($user->fresh()->avatarUrl());
        Volt::test('settings.photo')->call('removePhoto')->assertHasNoErrors()
            ->assertSee('Profile photo removed.')
            ->assertDispatched('account-photo-updated', fn ($event, $data) => $data['url'] === null);
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertNull($user->fresh()->avatarUrl());
        Storage::disk('avatars')->assertMissing($second);
        foreach (['name', 'email', 'role', 'password', 'email_verified_at'] as $attribute) {
            $this->assertSame($before[$attribute], $user->fresh()->getAttributes()[$attribute]);
        }
        if ($profile) {
            $this->assertTrue($profile->fresh()->is_public);
        }
    }

    #[DataProvider('formats')]
    public function test_real_supported_formats_are_decoded_and_served_with_private_image_headers(string $format, string $mime): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Volt::test('settings.photo')->set('photo', $this->photo($format))->call('savePhoto')->assertHasNoErrors();

        $response = $this->get($user->fresh()->avatarUrl())->assertOk()
            ->assertHeader('Content-Type', $mime)->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Vary', 'Cookie')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('inline;', $response->headers->get('Content-Disposition'));
        $bytes = $response->streamedContent();
        $this->assertSame($mime, getimagesizefromstring($bytes)['mime']);
    }

    #[DataProvider('invalidPhotos')]
    public function test_invalid_files_never_replace_a_saved_photo(string $case): void
    {
        $profile = FreelancerProfile::factory()->create(['is_public' => true]);
        $user = $profile->user;
        $this->actingAs($user);
        $saved = app(AccountPhotos::class)->replace($user, $this->photo());
        $path = $saved->avatar_path;
        $stored = Storage::disk('avatars')->get($path);
        $validPng = $this->photo();
        $validBytes = file_get_contents($validPng->getRealPath());
        $invalid = match ($case) {
            'SVG' => UploadedFile::fake()->createWithContent('photo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>'),
            'text' => UploadedFile::fake()->createWithContent('photo.png', 'This is not an image.'),
            'fake JPEG' => UploadedFile::fake()->createWithContent('photo.jpg', "\xff\xd8This is not JPEG image data"),
            'truncated PNG' => UploadedFile::fake()->createWithContent('photo.png', substr($validBytes, 0, 33)),
            'GIF' => $this->photo('gif'),
            'wrong extension' => UploadedFile::fake()->createWithContent('photo.svg', $validBytes),
            'too large' => UploadedFile::fake()->create('photo.png', 2049, 'image/png'),
            'too wide' => $this->photo('png', 4097, 1),
            'too tall' => $this->photo('png', 1, 4097),
        };

        Volt::test('settings.photo')->set('photo', $invalid)->assertHasErrors('photo')
            ->assertSet('previewReady', false)->call('savePhoto')->assertHasErrors('photo');

        $this->assertSame($path, $user->fresh()->avatar_path);
        $this->assertSame($stored, Storage::disk('avatars')->get($path));
        $this->assertSame([$path], Storage::disk('avatars')->allFiles());
        $this->assertSame(User::ROLE_FREELANCER, $user->fresh()->role);
        $this->assertTrue($profile->fresh()->is_public);
    }

    public function test_maximum_dimensions_and_size_boundary_are_accepted(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $file = $this->photo('png', 4096, 1);
        $file->size(2048);
        Volt::test('settings.photo')->set('photo', $file)->call('savePhoto')->assertHasNoErrors();
        $this->assertNotNull($user->fresh()->avatar_path);
    }

    public function test_insufficient_processing_memory_preserves_the_existing_photo_and_php_limit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $saved = app(AccountPhotos::class)->replace($user, $this->photo());
        $path = $saved->avatar_path;
        $bytes = Storage::disk('avatars')->get($path);
        $limit = ini_get('memory_limit');
        $this->app->bind(AccountPhotos::class, fn () => new class extends AccountPhotos
        {
            protected function availableMemoryBytes(): int
            {
                return 0;
            }
        });

        Volt::test('settings.photo')->set('photo', $this->photo())->call('savePhoto')
            ->assertHasErrors('photo')->assertSee('Choose a smaller image');

        $this->assertSame($path, $user->fresh()->avatar_path);
        $this->assertSame($bytes, Storage::disk('avatars')->get($path));
        $this->assertSame([$path], Storage::disk('avatars')->allFiles());
        $this->assertSame($limit, ini_get('memory_limit'));
    }

    public function test_no_upload_is_required_for_other_account_updates_and_is_required_to_save_a_photo(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Volt::test('settings.photo')->call('savePhoto')->assertHasErrors(['photo' => 'required']);
        Volt::test('settings.profile')->set('name', 'Changed name')->call('updateProfileInformation')->assertHasNoErrors();
        $this->assertSame('Changed name', $user->fresh()->name);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_initials_and_shared_avatars_are_rendered_in_the_account_navigation_and_card(): void
    {
        $user = User::factory()->create(['name' => 'Alex Morgan', 'role' => User::ROLE_CLIENT]);
        $this->actingAs($user);
        $html = $this->get(route('client.dashboard'))->assertOk()->getContent();
        $this->assertSame(3, substr_count($html, 'data-account-avatar'));
        $this->assertStringContainsString('AM', $html);
        $this->assertStringContainsString('account-photo-updated', $html);
        $this->assertStringContainsString('failed = true', $html);
        $this->get(route('settings.profile'))->assertOk()->assertSeeText('Profile photo')->assertSeeText('Save photo')->assertSeeText('Remove photo');
    }

    public function test_guests_cannot_view_photos_or_access_photo_actions(): void
    {
        $user = User::factory()->create();
        $this->get(route('account.photo', $user))->assertRedirectToRoute('login');
        $this->get(route('settings.profile'))->assertRedirectToRoute('login');
        Volt::test('settings.photo')->assertForbidden();
    }

    #[DataProvider('actions')]
    public function test_a_logged_out_account_cannot_save_or_remove_a_loaded_component(string $action): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $component = Volt::test('settings.photo')->set('photo', $this->photo());
        Auth::logout();
        $component->call($action)->assertForbidden();
        $this->assertNull($user->fresh()->avatar_path);
    }

    #[DataProvider('roles')]
    public function test_no_role_including_admin_can_view_or_change_another_accounts_photo(string $role): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $owner = app(AccountPhotos::class)->replace($owner, $this->photo());
        $other = User::factory()->create(['role' => $role]);
        $this->actingAs($other);
        $this->get($owner->avatarUrl())->assertForbidden();
        $this->assertFalse(Gate::allows('updatePhoto', $owner));

        Volt::test('settings.photo')->set('photo', $this->photo())
            ->call('savePhoto', ['user_id' => $owner->id, 'avatar_path' => $owner->avatar_path, 'role' => 'admin', 'is_public' => true])
            ->assertHasNoErrors();
        Volt::test('settings.photo')->call('removePhoto', ['user_id' => $owner->id])->assertHasNoErrors();
        Storage::disk('avatars')->assertExists($owner->avatar_path);
        $this->assertSame($owner->avatar_path, $owner->fresh()->avatar_path);
        $this->assertSame($role, $other->fresh()->role);
    }

    #[DataProvider('protectedFields')]
    public function test_sensitive_properties_are_not_exposed_to_photo_form_input(string $field): void
    {
        $this->actingAs(User::factory()->create());
        $component = Volt::test('settings.photo');
        $this->expectException(PublicPropertyNotFoundException::class);
        $component->set($field, 999);
    }

    public function test_the_photo_path_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();
        $user->fill(['avatar_path' => 'untrusted.png']);
        $this->assertNull($user->avatar_path);
    }

    #[DataProvider('actions')]
    public function test_policy_is_checked_for_page_access_and_each_action(string $action): void
    {
        $this->actingAs(User::factory()->create());
        $component = Volt::test('settings.photo')->set('photo', $this->photo());
        Gate::before(fn () => false);
        $component->call($action)->assertForbidden();
        Volt::test('settings.photo')->assertForbidden();
    }

    public function test_failed_database_save_cleans_up_the_new_file_and_keeps_the_previous_photo(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, $this->photo());
        $before = $user->avatar_path;
        User::saving(function (User $saving) use ($user): void {
            if ($saving->is($user) && $saving->isDirty('avatar_path')) {
                throw new RuntimeException('Simulated database failure');
            }
        });

        Volt::test('settings.photo')->set('photo', $this->photo())->call('savePhoto')->assertHasErrors('photo');
        $this->assertSame($before, $user->fresh()->avatar_path);
        $this->assertSame([$before], Storage::disk('avatars')->allFiles());
    }

    public function test_failed_removal_preserves_the_existing_photo_and_file(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, $this->photo());
        User::saving(function (): void {
            throw new RuntimeException('Simulated removal failure');
        });
        Volt::test('settings.photo')->call('removePhoto')->assertHasErrors('photo');
        $this->assertSame($user->avatar_path, $user->fresh()->avatar_path);
        Storage::disk('avatars')->assertExists($user->avatar_path);
    }

    public function test_rolling_back_an_enclosing_transaction_keeps_the_old_photo_and_removes_the_new_one(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $photos = app(AccountPhotos::class);
        $user = $photos->replace($user, $this->photo());
        $new = null;
        try {
            DB::transaction(function () use ($user, $photos, &$new): void {
                $new = $photos->replace($user, $this->photo())->avatar_path;
                Storage::disk('avatars')->assertExists($user->avatar_path);
                throw new RuntimeException('Abort enclosing transaction');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort enclosing transaction', $exception->getMessage());
        }
        $this->assertSame($user->avatar_path, $user->fresh()->avatar_path);
        Storage::disk('avatars')->assertExists($user->avatar_path);
        Storage::disk('avatars')->assertMissing($new);
    }

    public function test_account_deletion_cleans_up_its_photo_and_keeps_other_accounts_files(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($other);
        $other = app(AccountPhotos::class)->replace($other, $this->photo());
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, $this->photo());

        Volt::test('settings.delete-user-form')->set('password', 'wrong')->call('deleteUser')->assertHasErrors();
        Storage::disk('avatars')->assertExists($user->avatar_path);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors();
        $this->assertModelMissing($user);
        Storage::disk('avatars')->assertMissing($user->avatar_path);
        Storage::disk('avatars')->assertExists($other->avatar_path);
    }

    public function test_missing_photos_and_paths_outside_an_accounts_generated_directory_are_not_served_or_deleted(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);
        $owner = app(AccountPhotos::class)->replace($owner, $this->photo());
        $other->avatar_path = $owner->avatar_path;
        $other->save();
        $this->actingAs($other);
        $this->get(route('account.photo', $other))->assertNotFound();
        app(AccountPhotos::class)->remove($other);
        Storage::disk('avatars')->assertExists($owner->avatar_path);
        $this->get(route('account.photo', $other))->assertNotFound();
    }

    public function test_normalisation_removes_trailing_non_image_content(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $validPng = $this->photo();
        $payload = file_get_contents($validPng->getRealPath()).'<script>not image content</script>';
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->createWithContent('photo.png', $payload));
        $this->assertStringNotContainsString('<script>', Storage::disk('avatars')->get($user->avatar_path));
    }

    #[DataProvider('actions')]
    public function test_the_photo_service_rejects_cross_account_operations(string $action): void
    {
        $owner = User::factory()->create();
        $this->actingAs(User::factory()->create());
        $this->expectException(AuthorizationException::class);
        $photos = app(AccountPhotos::class);
        $action === 'savePhoto' ? $photos->replace($owner, $this->photo()) : $photos->remove($owner);
    }

    public function test_a_partial_storage_failure_is_cleaned_up_without_changing_the_current_photo(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $photos = app(AccountPhotos::class);
        $user = $photos->replace($user, $this->photo());
        $disk = Storage::disk('avatars');
        $failed = Mockery::mock(FilesystemAdapter::class);
        $failed->shouldReceive('put')->once()->andReturnUsing(function ($path) use ($disk): void {
            $disk->put($path, 'partial write');
            throw new RuntimeException('Simulated disk failure');
        });
        $failed->shouldReceive('delete')->once()->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('avatars')->andReturn($failed);

        try {
            $photos->replace($user, $this->photo());
            $this->fail('The simulated storage failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated disk failure', $exception->getMessage());
        }
        $this->assertSame($user->avatar_path, $user->fresh()->avatar_path);
        $this->assertSame([$user->avatar_path], $disk->allFiles());
    }

    public function test_rolling_back_account_deletion_keeps_the_photo(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, $this->photo());
        try {
            DB::transaction(function () use ($user): void {
                $user->delete();
                throw new RuntimeException('Cancel deletion');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Cancel deletion', $exception->getMessage());
        }
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar_path' => $user->avatar_path]);
        Storage::disk('avatars')->assertExists($user->avatar_path);
    }

    public function test_private_freelancer_profiles_remain_private_after_photo_changes(): void
    {
        $profile = FreelancerProfile::factory()->create(['is_public' => false]);
        $this->actingAs($profile->user);
        Volt::test('settings.photo')->set('photo', $this->photo())->call('savePhoto')->assertHasNoErrors();
        Volt::test('settings.photo')->call('removePhoto')->assertHasNoErrors();
        $this->assertFalse($profile->fresh()->is_public);
        $this->assertSame(User::ROLE_FREELANCER, $profile->user->fresh()->role);
    }

    private function photo(string $format = 'png', int $width = 120, int $height = 120): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        match ($format) {
            'jpg' => imagejpeg($image),
            'png' => imagepng($image),
            'webp' => imagewebp($image),
            'gif' => imagegif($image),
        };
        $contents = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('photo.'.$format, $contents);
    }

    public static function roles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'freelancer' => [User::ROLE_FREELANCER], 'admin' => [User::ROLE_ADMIN]];
    }

    public static function formats(): array
    {
        return ['JPEG' => ['jpg', 'image/jpeg'], 'PNG' => ['png', 'image/png'], 'WebP' => ['webp', 'image/webp']];
    }

    public static function actions(): array
    {
        return ['save' => ['savePhoto'], 'remove' => ['removePhoto']];
    }

    public static function invalidPhotos(): array
    {
        return array_combine($cases = ['SVG', 'text', 'fake JPEG', 'truncated PNG', 'GIF', 'wrong extension', 'too large', 'too wide', 'too tall'], array_map(fn ($case) => [$case], $cases));
    }

    public static function protectedFields(): array
    {
        return array_combine($fields = ['user_id', 'avatar_path', 'role', 'is_public'], array_map(fn ($field) => [$field], $fields));
    }
}
