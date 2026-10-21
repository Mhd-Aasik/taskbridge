<?php

namespace Tests\Feature;

use App\Actions\Notifications\ManageNotifications;
use App\Enums\NotificationEvent;
use App\Models\DisputeCase;
use App\Models\ProjectAssignment;
use App\Models\ProjectMessage;
use App\Models\ProjectReadCursor;
use App\Models\User;
use App\Support\NotificationDestinations;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsNotificationEvents;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use BuildsNotificationEvents, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-29 18:45:00', 'UTC'));
    }

    public function test_guests_redirect_and_gets_dropdowns_and_polling_never_change_read_state(): void
    {
        [$message, , [$recipient]] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $message)[0];
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->actingAs($recipient)->get(route('notifications.index'))->assertOk()->assertSeeText('You have a new project message.');
        Volt::test('notifications.bell')->call('refreshAlerts')->assertViewHas('unreadCount', 1);
        Volt::test('notifications.index')->call('refreshAlerts')->assertViewHas('unreadCount', 1);
        $this->assertNull($notification->fresh()->read_at);
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    #[DataProvider('notificationEvents')]
    public function test_opening_each_event_uses_an_authorized_named_destination_without_exposing_private_preview_data(NotificationEvent $event): void
    {
        [$origin, , [$recipient]] = $this->notificationEvent($event);
        $notification = $this->emitEvent($event, $origin)[0];
        $this->actingAs($recipient);
        $url = NotificationDestinations::forNotification($recipient, $notification);
        $this->assertNotNull($url, $event->value);
        $this->assertStringStartsWith(url('/').'/', $url);
        Volt::test('notifications.index')->call('openNotification', $notification->id)->assertRedirect($url);
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertDatabaseCount('project_read_cursors', 0);
        $this->get($url)->assertOk();
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    #[DataProvider('guessedMethods')]
    public function test_guessed_notification_ids_are_denied_even_for_admin_accounts(string $method, string $role): void
    {
        [$record] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $record)[0];
        $this->actingAs(User::factory()->create(['role' => $role]));
        $args = $method === 'setNotificationRead' ? [$notification->id, true] : [$notification->id];
        Volt::test('notifications.index')->call($method, ...$args)->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_read_unread_retries_preserve_event_data_and_do_not_change_conversation_cursors(): void
    {
        [$record, , [$recipient]] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $record)[0];
        $this->actingAs($recipient);
        $page = Volt::test('notifications.index')->call('setNotificationRead', $notification->id, true)->assertHasNoErrors()->assertViewHas('unreadCount', 0);
        $read = $notification->fresh()->getAttributes();
        $this->travel(1)->minutes();
        $page->call('setNotificationRead', $notification->id, true)->assertHasNoErrors();
        $this->assertSame($read, $notification->fresh()->getAttributes());
        $page->call('setNotificationRead', $notification->id, false)->assertHasNoErrors()->assertViewHas('unreadCount', 1);
        $unread = $notification->fresh()->getAttributes();
        $this->travel(1)->minutes();
        $page->call('setNotificationRead', $notification->id, false);
        $this->assertSame($unread, $notification->fresh()->getAttributes());
        $this->assertSame($read['data'], $unread['data']);
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public function test_mark_all_uses_captured_unread_set_and_leaves_concurrent_arrivals_unread(): void
    {
        [$first, , [$recipient]] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $firstAlert = $this->emitEvent(NotificationEvent::ProjectMessageSent, $first)[0];
        $this->actingAs($recipient);
        $page = Volt::test('notifications.index')->call('prepareMarkAll')->assertSet('capturedCount', 1)->assertSet('confirmingAll', true);
        $second = ProjectMessage::factory()->create(['project_assignment_id' => $first->project_assignment_id]);
        $secondAlert = $this->emitEvent(NotificationEvent::ProjectMessageSent, $second)[0];
        // Simulated arrival between capture/render and confirmation, not parallel MySQL execution.
        $page->call('confirmMarkAll')->assertHasNoErrors()->assertViewHas('unreadCount', 1)->assertSet('confirmingAll', false);
        $this->assertNotNull($firstAlert->fresh()->read_at);
        $this->assertNull($secondAlert->fresh()->read_at);
        $readAt = $firstAlert->fresh()->read_at;
        $page->call('confirmMarkAll')->assertHasNoErrors()->assertViewHas('unreadCount', 1);
        $this->assertEquals($readAt, $firstAlert->fresh()->read_at);
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public function test_captured_sets_are_owner_bound_expire_and_cannot_include_other_accounts(): void
    {
        [$first, , [$recipient]] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $first)[0];
        $this->actingAs($recipient);
        $set = app(ManageNotifications::class)->captureUnread();
        $this->actingAs(User::factory()->create());
        try {
            app(ManageNotifications::class)->markCapturedRead($set['token']);
            $this->fail('Another account cannot use this set.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('notifications', $exception->errors());
        }
        $this->actingAs($recipient);
        Cache::forget('notification-read-set:'.$set['token']);
        try {
            app(ManageNotifications::class)->markCapturedRead($set['token']);
            $this->fail('Expired sets must require recapture.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('notifications', $exception->errors());
        }
        $this->assertNull($notification->fresh()->read_at);
    }

    #[DataProvider('lockedFields')]
    public function test_mark_all_capture_fields_are_locked(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create());
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('notifications.index')->set($field, $value);
    }

    public function test_inbox_is_scoped_filtered_paginated_and_bell_is_bounded(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $recipient = $assignment->freelancer;
        $ids = [];
        for ($i = 0; $i < 23; $i++) {
            $message = ProjectMessage::factory()->create(['project_assignment_id' => $assignment->id, 'body' => 'Private body '.$i]);
            $alert = $this->emitEvent(NotificationEvent::ProjectMessageSent, $message)[0];
            $ids[] = $alert->id;
        }
        [$other] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $foreign = $this->emitEvent(NotificationEvent::ProjectMessageSent, $other)[0];
        $this->actingAs($recipient);
        app(ManageNotifications::class)->setRead($ids[0], true);
        $page = Volt::test('notifications.index')->assertViewHas('unreadCount', 22)->assertViewHas('alerts', fn ($alerts) => $alerts->total() === 23 && $alerts->count() === 20)
            ->assertDontSee($foreign->id)->assertDontSee('Private body');
        $page->call('setPage', 2)->assertViewHas('alerts', fn ($alerts) => $alerts->count() === 3)->set('filter', 'unread')->assertSet('paginators.page', 1)->assertViewHas('alerts', fn ($alerts) => $alerts->total() === 22);
        Volt::test('notifications.bell')->assertViewHas('recent', fn ($recent) => $recent->count() === 5)->assertViewHas('unreadCount', 22)->assertSee('Notifications: 22 unread')
            ->assertDontSee('Private body')->assertDontSee($foreign->id);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_inbox_parameters_are_rejected(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('notifications.index', [$field => $value]))->assertUnprocessable();
    }

    public function test_unknown_or_forged_payloads_never_render_script_text_or_arbitrary_destinations(): void
    {
        [$origin, , [$recipient]] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $origin)[0];
        DB::table('notifications')->where('id', $notification->id)->update(['type' => '<script>injected()</script>', 'data' => json_encode(['body' => '<script>body()</script>', 'url' => 'https://example.com/private'])]);
        $this->actingAs($recipient)->get(route('notifications.index'))->assertOk()->assertSeeText('This notification is no longer available.')->assertDontSee('injected()')->assertDontSee('body()')->assertDontSee('https://example.com/private');
        Volt::test('notifications.index')->call('openNotification', $notification->id)->assertRedirect(route('notifications.index'));
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_lost_authorization_or_missing_resource_opens_safe_inbox_fallback(): void
    {
        [$origin, , [$recipient]] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $origin)[0];
        $this->actingAs($recipient);
        $page = Volt::test('notifications.index');
        DB::table('users')->where('id', $recipient->id)->update(['role' => User::ROLE_ADMIN]);
        $page->call('openNotification', $notification->id)->assertRedirect(route('notifications.index'));
        $this->assertNull($notification->fresh()->read_at);
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public function test_missing_record_and_forged_other_assignment_record_cannot_be_opened(): void
    {
        [$origin, , [$recipient]] = $this->notificationEvent(NotificationEvent::ProjectMessageSent);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $origin)[0];
        $foreign = ProjectMessage::factory()->create();
        foreach ([999999, $foreign->id] as $id) {
            DB::table('notifications')->where('id', $notification->id)->update(['data' => json_encode(['record_id' => $id]), 'event_key' => NotificationEvent::ProjectMessageSent->value.':'.$id]);
            $this->actingAs($recipient);
            $result = app(ManageNotifications::class)->open($notification->id);
            $this->assertSame(['url' => route('notifications.index'), 'available' => false], $result);
        }
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_admin_dispute_context_viewing_preserves_participant_alert_and_message_read_states(): void
    {
        $case = DisputeCase::factory()->create();
        $message = ProjectMessage::factory()->create(['project_assignment_id' => $case->project_assignment_id]);
        $notification = $this->emitEvent(NotificationEvent::ProjectMessageSent, $message)[0];
        $cursor = ProjectReadCursor::factory()->create(['last_read_message_id' => $message->id]);
        $cursorBefore = $cursor->fresh()->getAttributes();
        $alertBefore = $notification->fresh()->getAttributes();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))->get(route('admin.disputes.show', $case))->assertOk();
        $this->assertSame($cursorBefore, $cursor->fresh()->getAttributes());
        $this->assertSame($alertBefore, $notification->fresh()->getAttributes());
    }

    public function test_empty_states_and_header_controls_are_present_for_each_role(): void
    {
        foreach ([User::ROLE_CLIENT, User::ROLE_FREELANCER, User::ROLE_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route($role.'.dashboard'))->assertOk()->assertSee('aria-label="Notifications: 0 unread"', false)->assertSee('aria-label="Recent notifications"', false)->assertSeeText('No notifications yet.')->assertSeeText('View full inbox')->assertSee('aria-label="Choose theme"', false);
        }
        $this->get(route('notifications.index'))->assertOk()->assertSeeText('No notifications yet.')->assertSee('wire:poll.20s.visible', false);
    }

    public static function guessedMethods(): array
    {
        $cases = [];
        foreach (['openNotification', 'setNotificationRead'] as $method) {
            foreach ([User::ROLE_CLIENT, User::ROLE_FREELANCER, User::ROLE_ADMIN] as $role) {
                $cases[] = [$method, $role];
            }
        }

        return $cases;
    }

    public static function lockedFields(): array
    {
        return [['readSetToken', '00000000-0000-4000-8000-000000000000'], ['capturedCount', 999999]];
    }

    public static function invalidFilters(): array
    {
        return [['filter', 'all-users'], ['filter', ['unread']], ['page', 0], ['page', 10001], ['page', 'bad']];
    }
}
