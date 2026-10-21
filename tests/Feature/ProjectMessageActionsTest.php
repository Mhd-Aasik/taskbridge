<?php

namespace Tests\Feature;

use App\Actions\Messages\ReadProjectMessages;
use App\Actions\Messages\SendProjectMessage;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectMessage;
use App\Models\User;
use App\Support\MessagePresentation;
use App\Support\ProjectMessaging;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProjectMessageActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-28 18:45:00', 'UTC'));
    }

    #[DataProvider('participantStatuses')]
    public function test_both_parties_send_on_supported_statuses_with_trusted_identity_and_no_work_changes(string $role, ProjectStatus $status): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $project = $assignment->project;
        $project->forceFill(['status' => $status])->save();
        $before = $project->fresh()->getAttributes();
        $terms = $assignment->fresh()->getAttributes();
        $sender = $role === 'client' ? $project->client : $assignment->freelancer;
        $this->actingAs($sender);
        $message = app(SendProjectMessage::class)->send($project->id, ['body' => "  First line\r\nSecond  line\rThird line  ", 'sender_id' => 999999, 'project_assignment_id' => 999999, 'sent_at' => '2000-01-01', 'submission_id' => 'forged', 'status' => 'in_progress'], (string) Str::uuid());
        $this->assertSame("First line\nSecond  line\nThird line", $message->body);
        $this->assertSame($sender->id, $message->sender_id);
        $this->assertSame($assignment->id, $message->project_assignment_id);
        $this->assertSame('2026-10-28 18:45:00', $message->sent_at->format('Y-m-d H:i:s'));
        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertSame($terms, $assignment->fresh()->getAttributes());
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public function test_exact_retries_preserve_original_message_and_time_even_when_rate_limited(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $token = (string) Str::uuid();
        $message = $this->send($assignment, "Original\r\ntext", $token)->fresh();
        $before = $message->getAttributes();
        for ($i = 1; $i < SendProjectMessage::LIMIT; $i++) {
            $this->send($assignment, 'Another message '.$i);
        }
        $this->travel(10)->seconds();
        $retry = $this->send($assignment, " Original\ntext ", strtoupper($token));
        $this->assertTrue($retry->is($message));
        $this->assertSame($before, $message->fresh()->getAttributes());
        $this->assertDatabaseCount('project_messages', 20);
        $this->expectException(ValidationException::class);
        $this->send($assignment, 'One more');
    }

    public function test_rate_limit_is_per_account_across_projects_and_expires(): void
    {
        $first = ProjectAssignment::factory()->create();
        $second = ProjectAssignment::factory()->create(['project_id' => Project::factory()->create(['client_id' => $first->project->client_id])->id]);
        $this->actingAs($first->project->client);
        for ($i = 0; $i < SendProjectMessage::LIMIT; $i++) {
            $this->send($first, 'Valid message '.$i);
        }
        try {
            $this->send($second);
            $this->fail('The same account cannot bypass the limit with another project.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('20 new messages per minute', $exception->errors()['body'][0]);
        }
        $this->actingAs($first->freelancer);
        $this->send($first);
        $this->travel(61)->seconds();
        $this->actingAs($first->project->client);
        $this->send($second);
        $this->assertDatabaseCount('project_messages', 22);
    }

    #[DataProvider('conflictKinds')]
    public function test_reused_token_cannot_change_message_or_actor_or_conversation(string $kind): void
    {
        $first = ProjectMessage::factory()->create();
        $assignment = $first->assignment;
        $this->actingAs($kind === 'actor' ? $assignment->freelancer : $first->sender);
        if ($kind === 'conversation') {
            $assignment = ProjectAssignment::factory()->create(['project_id' => Project::factory()->create(['client_id' => $first->sender_id])->id]);
        }
        $before = $first->fresh()->getAttributes();
        try {
            $this->send($assignment, $kind === 'body' ? 'Changed body' : $first->body, $first->submission_id);
            $this->fail('Conflicting reuse must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('message_conflict', $exception->errors());
        }
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertDatabaseCount('project_messages', 1);
    }

    #[DataProvider('deniedActors')]
    public function test_sending_rechecks_persisted_role_and_participant_identity(string $kind): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $actor = match ($kind) {
            'stale_client' => $assignment->project->client, 'stale_freelancer' => $assignment->freelancer, default => User::factory()->create(['role' => $kind])
        };
        $this->actingAs($actor);
        if (str_starts_with($kind, 'stale')) {
            DB::table('users')->where('id', $actor->id)->update(['role' => User::ROLE_ADMIN]);
        }
        $this->expectException(AuthorizationException::class);
        $this->send($assignment);
    }

    #[DataProvider('invalidBodies')]
    public function test_invalid_body_does_not_create_records_or_consume_rate_limit(mixed $body): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        try {
            app(SendProjectMessage::class)->send($assignment->project_id, ['body' => $body], (string) Str::uuid());
            $this->fail('Invalid message must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('body', $exception->errors());
        }
        $this->assertDatabaseCount('project_messages', 0);
        $this->assertSame(0, RateLimiter::attempts(SendProjectMessage::rateKey($assignment->project->client_id)));
    }

    public function test_maximum_body_is_accepted_and_outer_rollback_preserves_history(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $this->actingAs($assignment->project->client);
        $this->assertSame(5000, strlen($this->send($assignment, str_repeat('M', 5000))->body));
        try {
            DB::transaction(function () use ($assignment): void {
                $this->send($assignment, 'Rollback me');
                throw new RuntimeException('Abort');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort', $exception->getMessage());
        }
        $this->assertDatabaseCount('project_messages', 1);
    }

    public function test_read_cursor_advances_only_through_rendered_window_and_never_backwards(): void
    {
        $first = ProjectMessage::factory()->create();
        $assignment = $first->assignment;
        $viewer = $assignment->freelancer;
        $this->actingAs($viewer);
        $firstProof = MessagePresentation::issue($viewer, $assignment, [$first->id]);
        // Simulated arrival after rendering, before the explicit visible-window acknowledgment.
        $second = ProjectMessage::factory()->create(['project_assignment_id' => $assignment->id]);
        $cursor = app(ReadProjectMessages::class)->mark($assignment->project_id, $firstProof);
        $this->assertSame($first->id, $cursor->last_read_message_id);
        $this->assertSame(1, ProjectMessaging::totalUnread($viewer));
        $this->assertSame(0, ProjectMessaging::totalUnread($first->sender));
        $nextProof = MessagePresentation::issue($viewer, $assignment, [$first->id, $second->id]);
        $this->travel(1)->minutes();
        $updated = app(ReadProjectMessages::class)->mark($assignment->project_id, $nextProof)->fresh();
        $before = $updated->getAttributes();
        $this->travel(1)->minutes();
        app(ReadProjectMessages::class)->mark($assignment->project_id, $firstProof);
        app(ReadProjectMessages::class)->mark($assignment->project_id, $nextProof);
        $this->assertSame($before, $updated->fresh()->getAttributes());
        $this->assertSame(0, ProjectMessaging::totalUnread($viewer));
        $this->assertDatabaseCount('project_read_cursors', 1);
    }

    public function test_cursor_cannot_skip_an_undisplayed_incoming_backlog(): void
    {
        $first = ProjectMessage::factory()->create();
        $last = ProjectMessage::factory()->create(['project_assignment_id' => $first->project_assignment_id]);
        $viewer = $first->assignment->freelancer;
        $this->actingAs($viewer);
        $proof = MessagePresentation::issue($viewer, $first->assignment, [$last->id]);
        $this->assertNull(app(ReadProjectMessages::class)->mark($first->assignment->project_id, $proof));
        $this->assertDatabaseCount('project_read_cursors', 0);
        $this->assertSame(2, ProjectMessaging::totalUnread($viewer));
    }

    #[DataProvider('invalidPresentations')]
    public function test_forged_expired_or_mismatched_presentations_never_update_read_state(string $kind): void
    {
        $message = ProjectMessage::factory()->create();
        $assignment = $message->assignment;
        $viewer = $assignment->freelancer;
        $this->actingAs($viewer);
        $data = ['user' => $viewer->id, 'assignment' => $assignment->id, 'ids' => [$message->id], 'issued' => now()->timestamp];
        if ($kind === 'actor') {
            $data['user'] = $message->sender_id;
        }
        if ($kind === 'assignment') {
            $data['assignment'] = 99999;
        }
        if ($kind === 'message') {
            $data['ids'] = [ProjectMessage::factory()->create()->id];
        }
        if ($kind === 'expired') {
            $data['issued'] -= MessagePresentation::TTL + 1;
        }
        if ($kind === 'future') {
            $data['issued'] += 1;
        }
        if ($kind === 'missing') {
            $data['ids'] = [999999];
        }
        $proof = $kind === 'tampered' ? 'forged' : Crypt::encryptString(json_encode($data));
        try {
            app(ReadProjectMessages::class)->mark($assignment->project_id, $proof);
            $this->fail('Forged receipts must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('read', $exception->errors());
        }
        $this->assertDatabaseCount('project_read_cursors', 0);
    }

    public function test_read_cursors_and_unread_counts_are_isolated_between_users_and_projects(): void
    {
        $first = ProjectMessage::factory()->create();
        $assignment = $first->assignment;
        $secondAssignment = ProjectAssignment::factory()->create(['freelancer_id' => $assignment->freelancer_id]);
        ProjectMessage::factory()->count(2)->create(['project_assignment_id' => $secondAssignment->id]);
        ProjectMessage::factory()->create(['project_assignment_id' => $assignment->id, 'sender_id' => $assignment->freelancer_id]);
        $this->actingAs($assignment->freelancer);
        $proof = MessagePresentation::issue($assignment->freelancer, $assignment, [$first->id]);
        app(ReadProjectMessages::class)->mark($assignment->project_id, $proof);
        $this->assertSame(2, ProjectMessaging::totalUnread($assignment->freelancer));
        $this->assertSame(1, ProjectMessaging::totalUnread($assignment->project->client));
        $this->assertSame(0, ProjectMessaging::totalUnread(User::factory()->create(['role' => User::ROLE_FREELANCER])));
        $this->assertDatabaseCount('project_read_cursors', 1);
    }

    private function send(ProjectAssignment $assignment, string $body = 'A meaningful project message', ?string $token = null): ProjectMessage
    {
        return app(SendProjectMessage::class)->send($assignment->project_id, ['body' => $body], $token ?? (string) Str::uuid());
    }

    public static function participantStatuses(): array
    {
        $cases = [];
        foreach (['client', 'freelancer'] as $role) {
            foreach ([ProjectStatus::InProgress, ProjectStatus::Completed, ProjectStatus::Cancelled] as $status) {
                $cases[] = [$role, $status];
            }
        }

        return $cases;
    }

    public static function conflictKinds(): array
    {
        return [['body'], ['actor'], ['conversation']];
    }

    public static function deniedActors(): array
    {
        return array_map(fn ($actor) => [$actor], ['client', 'freelancer', 'admin', 'stale_client', 'stale_freelancer']);
    }

    public static function invalidBodies(): array
    {
        return [[''], [" \n\t "], ["\u{200B}"], [str_repeat('A', 5001)], [['invalid']], [null]];
    }

    public static function invalidPresentations(): array
    {
        return array_map(fn ($kind) => [$kind], ['actor', 'assignment', 'message', 'expired', 'future', 'missing', 'tampered']);
    }
}
