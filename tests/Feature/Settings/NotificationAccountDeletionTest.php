<?php

namespace Tests\Feature\Settings;

use App\Enums\NotificationEvent;
use App\Models\InAppNotification;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsNotificationEvents;
use Tests\TestCase;

class NotificationAccountDeletionTest extends TestCase
{
    use BuildsNotificationEvents, RefreshDatabase;

    #[DataProvider('roles')]
    public function test_personal_alerts_do_not_block_deletion_and_are_cleaned_without_touching_other_accounts(string $role): void
    {
        Storage::fake('avatars');
        if ($role === User::ROLE_ADMIN) {
            User::factory()->create(['role' => User::ROLE_ADMIN]);
        }
        $user = User::factory()->create(['role' => $role]);
        $other = User::factory()->create();
        // Old personal alerts may outlive their resource. They are not domain-history ownership.
        foreach ([$user, $other] as $recipient) {
            $alert = new InAppNotification;
            $alert->forceFill(['id' => (string) Str::uuid(), 'type' => NotificationEvent::ProjectMessageSent->value, 'notifiable_type' => $recipient->getMorphClass(), 'notifiable_id' => $recipient->id, 'event_key' => 'project_message_sent:999999', 'data' => ['record_id' => 999999]])->save();
        }
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');
        $this->assertModelMissing($user);
        Storage::disk('avatars')->assertMissing($user->avatar_path);
        $this->assertSame(1, $other->notifications()->count());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_existing_domain_history_guard_preserves_personal_alerts_and_photo_on_refusal(): void
    {
        Storage::fake('avatars');
        [$origin, , [$recipient]] = $this->notificationEvent(NotificationEvent::ReceiptAcknowledged);
        $alert = $this->emitEvent(NotificationEvent::ReceiptAcknowledged, $origin)[0];
        $this->actingAs($recipient);
        $recipient = app(AccountPhotos::class)->replace($recipient, UploadedFile::fake()->image('photo.png'));
        $before = $alert->fresh()->getAttributes();
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])->assertNoRedirect();
        $this->assertModelExists($recipient);
        Storage::disk('avatars')->assertExists($recipient->avatar_path);
        $this->assertSame($before, $alert->fresh()->getAttributes());
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }
}
