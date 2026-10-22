<?php

namespace Tests\Feature;

use App\Actions\Profiles\ChangeProfileVisibility;
use App\Models\User;
use App\Services\AccountPhotos;
use App\Support\FreelancerProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsFreelancerDiscovery;
use Tests\TestCase;

class FreelancerDiscoveryPhotoTest extends TestCase
{
    use BuildsFreelancerDiscovery, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('avatars');
    }

    public function test_photo_requires_separate_consent_and_owner_endpoint_remains_owner_only(): void
    {
        $profile = $this->visibleProfile();
        $this->actingAs($profile->user);
        $owner = app(AccountPhotos::class)->replace($profile->user, UploadedFile::fake()->image('photo.png'));
        $this->get(route('account.photo', $owner))->assertOk();
        $client = $this->discoveryClient();
        $this->get(route('client.freelancers.show', $profile->id))->assertOk()
            ->assertViewHas('profile', fn ($data) => $data['photo_url'] === null)->assertDontSee($owner->avatar_path);
        $this->get(route('client.freelancers.photo', $profile->id))->assertNotFound();
        $this->get(route('account.photo', $owner))->assertForbidden();
        $this->actingAs($owner);
        app(ChangeProfileVisibility::class)->photoConsent(true, FreelancerProfiles::revision($profile->fresh()));
        $this->actingAs($client)->get(route('client.freelancers.photo', $profile->id))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_shared_photo_is_private_noncacheable_and_changes_after_replacement(): void
    {
        $profile = $this->visibleProfile(['share_account_photo' => true]);
        $this->actingAs($profile->user);
        $owner = app(AccountPhotos::class)->replace($profile->user, UploadedFile::fake()->image('one.png', 20, 20));
        $firstPath = $owner->avatar_path;
        $client = $this->discoveryClient();
        $url = route('client.freelancers.photo', $profile->id);
        $response = $this->get($url)->assertOk()->assertHeader('Vary', 'Cookie')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $first = $response->streamedContent();
        $this->actingAs($owner);
        $owner = app(AccountPhotos::class)->replace($owner, UploadedFile::fake()->image('two.png', 30, 30));
        Storage::disk('avatars')->assertMissing($firstPath);
        $response = $this->actingAs($client)->get($url, ['If-None-Match' => 'previous-image'])->assertOk();
        $this->assertNotSame($first, $response->streamedContent());
        $this->assertTrue($profile->fresh()->share_account_photo);
    }

    #[DataProvider('revocations')]
    public function test_subsequent_photo_requests_are_refused_after_visibility_or_consent_revocation(string $case): void
    {
        $profile = $this->visibleProfile(['share_account_photo' => true]);
        $this->actingAs($profile->user);
        $owner = app(AccountPhotos::class)->replace($profile->user, UploadedFile::fake()->image('photo.png'));
        $client = $this->discoveryClient();
        $url = route('client.freelancers.photo', $profile->id);
        $this->get($url)->assertOk();
        $this->actingAs($owner);
        match ($case) {
            'consent' => app(ChangeProfileVisibility::class)->photoConsent(false, FreelancerProfiles::revision($profile->fresh())),
            'unpublish' => app(ChangeProfileVisibility::class)->unpublish(FreelancerProfiles::revision($profile->fresh())),
            'suspension' => $owner->forceFill(['suspended_at' => now(), 'access_version' => 1])->save(),
            'role' => $owner->forceFill(['role' => 'client'])->save(),
        };
        $response = $this->actingAs($client)->get($url, ['If-Modified-Since' => now()->toRfc7231String()])->assertNotFound();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Storage::disk('avatars')->assertExists($owner->avatar_path);
    }

    public function test_missing_or_removed_photo_uses_initials_and_never_exposes_private_path(): void
    {
        $profile = $this->visibleProfile(['share_account_photo' => true]);
        $profile->user->update(['name' => 'Ari Silva']);
        $this->actingAs($profile->user);
        $owner = app(AccountPhotos::class)->replace($profile->user, UploadedFile::fake()->image('photo.png'));
        Storage::disk('avatars')->delete($owner->avatar_path);
        $client = $this->discoveryClient();
        $this->get(route('client.freelancers.show', $profile->id))->assertOk()->assertSeeText('AS')->assertSee('x-on:error="failed = true"', false)->assertDontSee($owner->avatar_path);
        $this->get(route('client.freelancers.photo', $profile->id))->assertNotFound();
        $this->actingAs($owner);
        app(AccountPhotos::class)->remove($owner);
        $this->actingAs($client)->get(route('client.freelancers.photo', $profile->id))->assertNotFound();
        $this->assertTrue($profile->fresh()->is_public);
        $this->assertTrue($profile->fresh()->share_account_photo);
    }

    public function test_guests_freelancers_and_administrators_cannot_use_the_client_photo_endpoint(): void
    {
        $profile = $this->visibleProfile(['share_account_photo' => true]);
        $url = route('client.freelancers.photo', $profile->id);
        $this->get($url)->assertRedirect(route('login'));
        foreach (['freelancer', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get($url)->assertForbidden();
        }
    }

    public function test_owner_preview_uses_authorized_owner_photo_only_when_consent_is_enabled(): void
    {
        $profile = $this->professionalProfile();
        $this->actingAs($profile->user);
        $owner = app(AccountPhotos::class)->replace($profile->user, UploadedFile::fake()->image('photo.png'));
        $this->get(route('freelancer.profile.preview'))->assertOk()->assertViewHas('profile', fn ($data) => $data['photo_url'] === null);
        app(ChangeProfileVisibility::class)->photoConsent(true, FreelancerProfiles::revision($profile));
        $this->get(route('freelancer.profile.preview'))->assertOk()->assertViewHas('profile', fn ($data) => $data['photo_url'] === route('account.photo', $owner));
        $this->assertFalse($profile->fresh()->is_public);
    }

    public static function revocations(): array
    {
        return ['consent' => ['consent'], 'unpublish' => ['unpublish'], 'suspension' => ['suspension'], 'role' => ['role']];
    }
}
