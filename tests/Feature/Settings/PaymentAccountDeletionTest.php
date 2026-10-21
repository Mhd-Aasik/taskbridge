<?php

namespace Tests\Feature\Settings;

use App\Models\FreelancerProfile;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('paymentActors')]
    public function test_payment_history_guard_preserves_legacy_actor_profile_and_avatar_before_cleanup(string $kind, string $role): void
    {
        Storage::fake('avatars');
        $actor = User::factory()->create(['role' => $role]);
        $record = $kind === 'report' ? PaymentReport::factory()->create(['reported_by' => $actor->id]) : PaymentAcknowledgment::factory()->create(['acknowledged_by' => $actor->id]);
        $before = $record->fresh()->getAttributes();
        $this->actingAs($actor);
        $actor = app(AccountPhotos::class)->replace($actor, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $actor->id, 'is_public' => false]);
        $actorBefore = $actor->fresh()->getAttributes();
        $profileBefore = $profile->fresh()->getAttributes();
        $photo = Storage::disk('avatars')->get($actor->avatar_path);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])
            ->assertSeeText('Your account cannot be deleted because it has payment history.')->assertNoRedirect();
        $this->assertAuthenticatedAs($actor);
        $this->assertSame($actorBefore, $actor->fresh()->getAttributes());
        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame($photo, Storage::disk('avatars')->get($actor->avatar_path));
    }

    #[DataProvider('roles')]
    public function test_no_history_accounts_still_delete_without_altering_unrelated_payment_history(string $role): void
    {
        Storage::fake('avatars');
        $receipt = PaymentAcknowledgment::factory()->create()->fresh();
        $reportBefore = $receipt->report->getAttributes();
        $receiptBefore = $receipt->getAttributes();
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor);
        $actor = app(AccountPhotos::class)->replace($actor, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $actor->id]);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');
        $this->assertGuest();
        $this->assertModelMissing($actor);
        $this->assertModelMissing($profile);
        Storage::disk('avatars')->assertMissing($actor->avatar_path);
        $this->assertSame($reportBefore, $receipt->report->fresh()->getAttributes());
        $this->assertSame($receiptBefore, $receipt->fresh()->getAttributes());
    }

    public static function paymentActors(): array
    {
        $cases = [];
        foreach (['report', 'receipt'] as $kind) {
            foreach ([User::ROLE_CLIENT, User::ROLE_FREELANCER, User::ROLE_ADMIN] as $role) {
                $cases[] = [$kind, $role];
            }
        }

        return $cases;
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }
}
