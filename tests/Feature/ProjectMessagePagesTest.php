<?php

namespace Tests\Feature;

use App\Actions\Messages\SendProjectMessage;
use App\Enums\ProjectStatus;
use App\Models\DisputeCase;
use App\Models\FreelancerProfile;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectMessage;
use App\Models\ProjectReadCursor;
use App\Models\User;
use App\Policies\ProjectMessagePolicy;
use App\Support\DisputeConversation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProjectMessagePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 18:45:00', 'UTC'));
    }

    public function test_guest_routes_redirect_and_get_does_not_mark_messages_read(): void
    {
        $message = ProjectMessage::factory()->create();
        $this->get(route('messages.index'))->assertRedirect(route('login'));
        $this->get(route('projects.messages', $message->assignment->project_id))->assertRedirect(route('login'));
        $this->actingAs($message->assignment->freelancer)->get(route('projects.messages', $message->assignment->project_id))->assertOk()->assertSeeText($message->body);
        $this->assertDatabaseCount('project_read_cursors', 0);
        $this->assertDatabaseCount('project_messages', 1);
    }

    #[DataProvider('roles')]
    public function test_unrelated_accounts_and_admins_cannot_open_participant_conversations(string $role): void
    {
        $message = ProjectMessage::factory()->create();
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route('projects.messages', $message->assignment->project_id))->assertForbidden();
        if ($role === User::ROLE_ADMIN) {
            $this->get(route('messages.index'))->assertForbidden();
        }
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public function test_unassigned_projects_have_no_conversation_and_unsupported_status_is_denied(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->client)->get(route('projects.messages', $project))->assertNotFound();
        $assignment = ProjectAssignment::factory()->create();
        $assignment->project->forceFill(['status' => ProjectStatus::Open])->save();
        $this->actingAs($assignment->project->client)->get(route('projects.messages', $assignment->project_id))->assertForbidden();
    }

    #[DataProvider('participants')]
    public function test_both_parties_send_escaped_messages_and_have_working_links(string $role): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $user = $role === 'client' ? $assignment->project->client : $assignment->freelancer;
        $this->actingAs($user);
        $this->get(route('projects.messages', $assignment->project_id))->assertOk()->assertSeeText('No messages yet.')->assertSeeText('Authorised Admins may review this conversation')
            ->assertSee('wire:poll.10s.visible="pollConversation"', false)->assertSee('tabindex="0"', false);
        $page = Volt::test('messages.show', ['project' => $assignment->project])->set('body', "First\n<script>unsafe()</script>")->call('sendMessage')
            ->assertHasNoErrors()->assertSet('body', '')->assertSeeText('Message sent.')->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)->assertDontSee('<script>unsafe()</script>', false)->assertSeeText('You')->assertSeeText('29 October 2026, 12:15 am');
        $this->assertDatabaseCount('project_messages', 1);
        $this->assertDatabaseCount('project_read_cursors', 0);
        $detail = $role === 'client' ? route('client.projects.show', $assignment->project_id) : route('freelancer.assignments.show', $assignment);
        $this->get($detail)->assertOk()->assertSee(route('projects.messages', $assignment->project_id))->assertSeeText('Project messages');
        $this->get(route($role.'.dashboard'))->assertOk()->assertSee(route('messages.index'));
    }

    public function test_validation_and_rate_limit_keep_unsent_text_and_stored_history(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $page = Volt::test('messages.show', ['project' => $assignment->project])->set('body', '   ')->call('sendMessage')->assertHasErrors(['body'])->assertSet('body', '   ');
        for ($i = 0; $i < SendProjectMessage::LIMIT; $i++) {
            RateLimiter::hit(SendProjectMessage::rateKey($assignment->project->client_id), 60);
        }
        $page->set('body', 'Keep this unsent text')->call('sendMessage')->assertHasErrors(['body'])->assertSet('body', 'Keep this unsent text')->assertSeeText('Please wait');
        $this->assertDatabaseCount('project_messages', 0);
    }

    public function test_response_loss_after_commit_retries_without_duplicate_and_renews_composer(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $real = new SendProjectMessage;
        $mock = Mockery::mock(SendProjectMessage::class)->makePartial();
        $mock->shouldReceive('send')->once()->andReturnUsing(function (...$args) use ($real): never {
            $real->send(...$args);
            throw new RuntimeException('Simulated lost response after commit');
        });
        $this->app->instance(SendProjectMessage::class, $mock);
        $this->actingAs($assignment->project->client);
        $page = Volt::test('messages.show', ['project' => $assignment->project]);
        $originalToken = $page->get('submissionId');
        $page->set('body', 'Preserve my text')->call('sendMessage')->assertHasErrors(['body'])->assertSet('body', 'Preserve my text')->assertSet('submissionId', $originalToken);
        $before = ProjectMessage::sole()->getAttributes();
        $this->app->instance(SendProjectMessage::class, $real);
        $page->call('sendMessage')->assertHasNoErrors()->assertSet('body', '');
        $this->assertNotSame($originalToken, $page->get('submissionId'));
        $this->assertSame($before, ProjectMessage::sole()->getAttributes());
        $this->assertDatabaseCount('project_messages', 1);
    }

    public function test_changing_text_after_unknown_outcome_conflicts_then_can_explicitly_start_new_message(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $page = Volt::test('messages.show', ['project' => $assignment->project]);
        app(SendProjectMessage::class)->send($assignment->project_id, ['body' => 'Original committed text'], $page->get('submissionId'));
        $page->set('body', 'Different new text')->call('sendMessage')->assertHasErrors(['message_conflict'])->assertSet('body', 'Different new text')
            ->call('renewSubmission')->assertSet('body', 'Different new text')->call('sendMessage')->assertHasNoErrors();
        $this->assertDatabaseCount('project_messages', 2);
        $this->assertSame('Original committed text', ProjectMessage::oldest('id')->first()->body);
    }

    #[DataProvider('protectedProperties')]
    public function test_conversation_and_presentation_properties_are_locked(string $property, mixed $value): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('messages.show', ['project' => $assignment->project])->set($property, $value);
    }

    #[DataProvider('staleMethods')]
    public function test_every_history_send_poll_and_read_action_rechecks_persisted_role(string $method): void
    {
        $message = ProjectMessage::factory()->create();
        $this->actingAs($message->sender);
        $page = Volt::test('messages.show', ['project' => $message->assignment->project])->set('body', 'Entered before authorization changed');
        $proof = $page->viewData('presentation');
        DB::table('users')->where('id', $message->sender_id)->update(['role' => User::ROLE_ADMIN]);
        $args = $method === 'markDisplayedRead' ? [$proof] : [];
        $page->call($method, ...$args)->assertForbidden();
        $this->assertDatabaseCount('project_messages', 1);
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public function test_history_uses_bounded_stable_windows_and_polls_only_following_windows(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $messages = ProjectMessage::factory()->count(125)->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->project->client); // All own messages, so start at latest 30.
        $page = Volt::test('messages.show', ['project' => $assignment->project]);
        $this->assertSame($messages->slice(95)->values()->modelKeys(), $page->get('messageIds'));
        $page->call('loadOlder')->call('loadOlder')->call('loadOlder');
        $olderIds = $page->get('messageIds');
        $this->assertCount(90, $olderIds);
        $this->assertSame($messages->slice(5, 90)->values()->modelKeys(), $olderIds);
        $new = ProjectMessage::factory()->create(['project_assignment_id' => $assignment->id, 'sender_id' => $assignment->freelancer_id]);
        $page->call('pollConversation')->assertSet('messageIds', $olderIds)->assertSet('viewingOlder', true)->assertSeeText('Show newer messages');
        $page->call('goToUnread')->assertSet('messageIds', [$new->id])->assertSet('viewingOlder', false);
        $newer = ProjectMessage::factory()->create(['project_assignment_id' => $assignment->id, 'sender_id' => $assignment->freelancer_id]);
        $page->set('followLatest', false)->call('pollConversation')->assertSet('messageIds', [$new->id]);
        $page->set('followLatest', true)->call('pollConversation')->assertSet('messageIds', [$new->id, $newer->id]);
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    #[DataProvider('participants')]
    public function test_ownership_changes_after_mount_deny_send_before_any_mutation(string $role): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $actor = $role === 'client' ? $assignment->project->client : $assignment->freelancer;
        $this->actingAs($actor);
        $page = Volt::test('messages.show', ['project' => $assignment->project])->set('body', 'Entered before ownership changed');
        $replacement = User::factory()->create(['role' => $role]);
        $role === 'client'
            ? DB::table('projects')->where('id', $assignment->project_id)->update(['client_id' => $replacement->id])
            : DB::table('project_assignments')->where('id', $assignment->id)->update(['freelancer_id' => $replacement->id]);
        $page->call('sendMessage')->assertForbidden();
        $this->assertDatabaseCount('project_messages', 0);
    }

    public function test_initial_long_unread_backlog_is_presented_in_contiguous_batches_before_cursor_advances(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $messages = ProjectMessage::factory()->count(65)->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->freelancer);
        $page = Volt::test('messages.show', ['project' => $assignment->project]);
        $this->assertSame($messages->take(30)->modelKeys(), $page->get('messageIds'));
        $page->call('markDisplayedRead', $page->viewData('presentation'))->assertViewHas('unread', 35)->call('loadNewer');
        $page->call('markDisplayedRead', $page->viewData('presentation'))->assertViewHas('unread', 5)->call('loadNewer');
        $page->call('markDisplayedRead', $page->viewData('presentation'))->assertViewHas('unread', 0);
        $this->assertSame($messages->last()->id, ProjectReadCursor::sole()->last_read_message_id);
    }

    public function test_arrival_between_render_and_read_is_not_marked_and_badge_is_account_scoped(): void
    {
        $first = ProjectMessage::factory()->create();
        $assignment = $first->assignment;
        $this->actingAs($assignment->freelancer);
        $page = Volt::test('messages.show', ['project' => $assignment->project]);
        $presentation = $page->viewData('presentation');
        $second = ProjectMessage::factory()->create(['project_assignment_id' => $assignment->id]);
        $page->call('markDisplayedRead', $presentation)->assertViewHas('unread', 1)->assertDontSee('data-message-id="'.$second->id.'"', false);
        Volt::test('messages.unread-badge')->assertViewHas('unread', 1)->assertSee('1 unread incoming message');
        Volt::test('messages.unread-badge', ['projectId' => $assignment->project_id])->assertViewHas('unread', 1);
        $this->actingAs($first->sender);
        Volt::test('messages.unread-badge')->assertViewHas('unread', 0);
    }

    public function test_conversation_list_is_scoped_paginated_and_contains_only_needed_display_information(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $last = null;
        for ($i = 1; $i <= 12; $i++) {
            $assignment = ProjectAssignment::factory()->create(['project_id' => Project::factory()->create(['client_id' => $owner->id, 'title' => 'Owned project '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)])->id]);
            ProjectMessage::factory()->create(['project_assignment_id' => $assignment->id, 'sender_id' => $assignment->freelancer_id]);
            $last = $assignment;
        }
        $foreign = ProjectMessage::factory()->create(['body' => 'Foreign conversation secret']);
        $this->actingAs($owner);
        $page = Volt::test('messages.index')->assertViewHas('totalUnread', 12)->assertSeeInOrder(['Owned project 12', 'Owned project 11'])->assertDontSee('Owned project 01')->assertDontSee('Foreign conversation secret')
            ->assertDontSee($last->freelancer->email)->assertDontSee($foreign->sender->email);
        $page->call('setPage', 2)->assertSee('Owned project 01');
        $this->get(route('messages.index', ['page' => 10001]))->assertUnprocessable();
    }

    public function test_messages_exclude_counterparty_private_account_data_and_wrap_escaped_content(): void
    {
        $message = ProjectMessage::factory()->create(['body' => "Line one\n<script>unsafe()</script>"]);
        $sender = $message->sender;
        $sender->forceFill(['avatar_path' => 'users/private/avatar.png'])->save();
        FreelancerProfile::factory()->create(['user_id' => $sender->id, 'headline' => 'Private account headline', 'is_public' => false]);
        $this->actingAs($message->assignment->freelancer)->get(route('projects.messages', $message->assignment->project_id))->assertOk()
            ->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)->assertSee('whitespace-pre-wrap', false)->assertSee('overflow-wrap:anywhere', false)
            ->assertDontSee('<script>unsafe()</script>', false)->assertDontSee($sender->email)->assertDontSee($sender->avatar_path)->assertDontSee('Private account headline')->assertDontSee($message->submission_id);
    }

    public function test_admin_dispute_context_is_scoped_read_only_and_does_not_create_or_advance_cursors(): void
    {
        $case = DisputeCase::factory()->create();
        $message = ProjectMessage::factory()->create(['project_assignment_id' => $case->project_assignment_id, 'body' => "Evidence\n<script>context()</script>"]);
        $cursor = ProjectReadCursor::factory()->create(['last_read_message_id' => $message->id]);
        ProjectMessage::factory()->create(['project_assignment_id' => $case->project_assignment_id]);
        $foreign = ProjectMessage::factory()->create(['body' => 'Unrelated message secret']);
        $before = $cursor->fresh()->getAttributes();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get(route('admin.disputes.show', $case))->assertOk()->assertSeeText('Current project conversation')->assertSeeText('does not mark participants')
            ->assertSee('&lt;script&gt;context()&lt;/script&gt;', false)->assertDontSee('Unrelated message secret')->assertDontSee($message->sender->email)->assertDontSee($foreign->submission_id);
        $this->assertSame([$message->id, $message->id + 1], DisputeConversation::messages($case->id)->pluck('id')->all());
        $this->assertDatabaseCount('project_read_cursors', 1);
        $this->assertSame($before, $cursor->fresh()->getAttributes());
        $policy = new ProjectMessagePolicy;
        $this->assertFalse($policy->viewForDispute($admin, $case, $foreign->assignment, $foreign->assignment->project));
        $this->get(route('projects.messages', $case->assignment->project_id))->assertForbidden();
    }

    public function test_empty_list_does_not_create_conversations_or_read_cursors(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]))->get(route('messages.index'))->assertOk()->assertSeeText('No project conversations yet.');
        $this->assertDatabaseCount('project_messages', 0);
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public function test_conversation_list_refresh_updates_counts_without_marking_incoming_messages_read(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->freelancer);
        $page = Volt::test('messages.index')->assertViewHas('totalUnread', 0);
        ProjectMessage::factory()->create(['project_assignment_id' => $assignment->id]);
        $page->call('refreshConversations')->assertViewHas('totalUnread', 1);
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function participants(): array
    {
        return [['client'], ['freelancer']];
    }

    public static function protectedProperties(): array
    {
        return [['projectId', 99999], ['submissionId', '00000000-0000-4000-8000-000000000000'], ['messageIds', [99999]], ['viewingOlder', true]];
    }

    public static function staleMethods(): array
    {
        return array_map(fn ($method) => [$method], ['sendMessage', 'pollConversation', 'loadOlder', 'loadNewer', 'goToUnread', 'markDisplayedRead', 'renewSubmission']);
    }
}
