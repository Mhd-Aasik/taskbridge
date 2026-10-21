<?php

namespace Tests\Feature;

use App\Actions\Feedback\SubmitProjectRating;
use App\Enums\ProjectStatus;
use App\Models\DisputeCase;
use App\Models\FreelancerProfile;
use App\Models\InAppNotification;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\ProjectRating;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsProjectFeedback;
use Tests\TestCase;

class ProjectFeedbackActionsTest extends TestCase
{
    use BuildsProjectFeedback, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-01 18:30:00', 'UTC'));
    }

    public function test_guest_cannot_call_the_submission_action(): void
    {
        $assignment = $this->completedAssignment();
        $this->expectException(HttpException::class);
        app(SubmitProjectRating::class)->submit($assignment->project_id, ['rating' => 5, 'confirmed' => true], (string) Str::uuid());
    }

    #[DataProvider('unrelatedRoles')]
    public function test_unrelated_roles_cannot_call_the_submission_action(string $role): void
    {
        $assignment = $this->completedAssignment();
        $this->expectException(AuthorizationException::class);
        $this->submitFeedback($assignment, User::factory()->create(['role' => $role]));
    }

    public function test_a_failed_rating_save_cannot_emit_a_notification(): void
    {
        $assignment = $this->completedAssignment();
        ProjectRating::creating(fn () => false);
        try {
            $this->submitFeedback($assignment, $assignment->project->client);
            $this->fail('A failed save must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('feedback could not be recorded', $exception->getMessage());
        }
        $this->assertDatabaseCount('project_ratings', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_expiry_after_opening_a_form_is_enforced_without_losing_entered_values(): void
    {
        $assignment = $this->completedAssignment(CarbonImmutable::now('UTC')->subDays(14)->addSecond());
        $this->actingAs($assignment->freelancer);
        $page = Volt::test('projects.feedback', ['project' => $assignment->project])->set('rating', 5)->set('feedback', 'Retained after expiry')->set('confirmed', true);
        $this->travel(1)->seconds();
        $page->call('submit')->assertHasErrors(['feedback'])->assertSet('rating', 5)->assertSet('feedback', 'Retained after expiry')->assertSeeText('The 14-day feedback window has closed.');
        $this->assertDatabaseCount('project_ratings', 0);
    }

    #[DataProvider('parties')]
    public function test_both_parties_submit_once_with_server_owned_identities_and_private_notifications(bool $client): void
    {
        $assignment = $this->completedAssignment();
        $profile = FreelancerProfile::factory()->create(['user_id' => $assignment->freelancer_id]);
        $actor = $client ? $assignment->project->client : $assignment->freelancer;
        $other = $client ? $assignment->freelancer : $assignment->project->client;
        $token = (string) Str::uuid();
        $saved = $this->submitFeedback($assignment, $actor, ['rating' => '5', 'feedback' => "  Reliable collaboration.\nClear communication.  ", 'reviewer_id' => $other->id, 'reviewee_id' => $actor->id,
            'project_assignment_id' => 999999, 'submitted_at' => '2030-01-01', 'submission_id' => 'forged', 'role' => 'admin', 'is_public' => true], $token)->fresh();
        $this->assertSame($actor->id, $saved->reviewer_id);
        $this->assertSame($other->id, $saved->reviewee_id);
        $this->assertSame($assignment->id, $saved->project_assignment_id);
        $this->assertSame(5, $saved->rating);
        $this->assertSame("Reliable collaboration.\nClear communication.", $saved->feedback);
        $this->assertEquals(now('UTC'), $saved->submitted_at);
        $this->assertSame($token, $saved->submission_id);
        $this->assertFalse($profile->fresh()->is_public);
        $this->assertSame($actor->role, $actor->fresh()->role);
        $alert = $other->notifications()->sole();
        $this->assertSame(['record_id' => $saved->id], $alert->data);
        $this->assertSame('project_feedback_submitted:'.$saved->id, $alert->event_key);
        $this->assertSame(0, $actor->notifications()->count());
    }

    #[DataProvider('windowBoundaries')]
    public function test_window_is_exactly_fourteen_days_with_an_exclusive_closing_instant(int $seconds, bool $allowed): void
    {
        $start = CarbonImmutable::parse('2026-10-30 18:29:59', 'UTC');
        $assignment = $this->completedAssignment($start);
        $this->travelTo($start->addSeconds($seconds));
        if (! $allowed) {
            $this->expectException(ValidationException::class);
        }
        $saved = $this->submitFeedback($assignment, $assignment->project->client);
        $this->assertEquals($start->addSeconds($seconds), $saved->submitted_at);
    }

    #[DataProvider('ineligibleProjects')]
    public function test_non_completed_or_incomplete_historical_metadata_cannot_open_a_window(array $changes): void
    {
        $assignment = $this->completedAssignment();
        if (($changes['completed_by'] ?? null) === 999999) {
            $changes['completed_by'] = $assignment->freelancer_id;
        }
        DB::table('projects')->where('id', $assignment->project_id)->update($changes);
        $this->expectException(ValidationException::class);
        $this->submitFeedback($assignment, $assignment->project->client);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_fields_never_create_feedback_or_alerts(string $field, mixed $value): void
    {
        $assignment = $this->completedAssignment();
        try {
            $this->submitFeedback($assignment, $assignment->project->client, [$field => $value]);
            $this->fail('The value must fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('project_ratings', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    #[DataProvider('optionalFeedback')]
    public function test_optional_feedback_normalizes_blanks_and_preserves_the_maximum_length(?string $value, ?string $expected): void
    {
        $assignment = $this->completedAssignment();
        $saved = $this->submitFeedback($assignment, $assignment->project->client, ['rating' => 1, 'feedback' => $value]);
        $this->assertSame($expected, $saved->feedback);
    }

    public function test_exact_normalized_retry_after_expiry_preserves_original_content_times_and_alert(): void
    {
        $assignment = $this->completedAssignment();
        $token = (string) Str::uuid();
        $saved = $this->submitFeedback($assignment, $assignment->project->client, ['feedback' => '  Original feedback  '], $token);
        $before = $saved->getAttributes();
        $alert = InAppNotification::sole()->getAttributes();
        $this->travel(20)->days();
        $retry = $this->submitFeedback($assignment, $assignment->project->client, ['feedback' => 'Original feedback'], $token);
        $this->assertSame($before, $retry->fresh()->getAttributes());
        $this->assertSame($alert, InAppNotification::sole()->getAttributes());
        $this->assertDatabaseCount('project_ratings', 1);
    }

    #[DataProvider('conflictingRetries')]
    public function test_conflicting_attempts_preserve_the_first_submission(string $field, mixed $value): void
    {
        $assignment = $this->completedAssignment();
        $token = (string) Str::uuid();
        $saved = $this->submitFeedback($assignment, $assignment->project->client, [], $token);
        $before = $saved->getAttributes();
        try {
            $this->submitFeedback($assignment, $assignment->project->client, $field === 'token' ? [] : [$field => $value], $field === 'token' ? (string) Str::uuid() : $token);
            $this->fail('A conflict must not rewrite feedback.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('feedback', $exception->errors());
        }
        $this->assertSame($before, $saved->fresh()->getAttributes());
        $this->assertDatabaseCount('notifications', 1);
    }

    #[DataProvider('independentHistory')]
    public function test_payment_and_dispute_history_are_not_prerequisites(string $history): void
    {
        $assignment = $this->completedAssignment();
        if ($history === 'payment' || $history === 'receipt') {
            $report = PaymentReport::factory()->create(['project_assignment_id' => $assignment->id]);
            if ($history === 'receipt') {
                PaymentAcknowledgment::factory()->create(['payment_report_id' => $report->id]);
            }
        }
        if ($history === 'dispute') {
            DisputeCase::factory()->create(['project_assignment_id' => $assignment->id]);
        }
        $this->submitFeedback($assignment, $assignment->freelancer);
        $this->assertDatabaseCount('project_ratings', 1);
        $this->assertSame(ProjectStatus::Completed, $assignment->project->fresh()->status);
    }

    public function test_notification_failure_and_enclosing_rollback_leave_no_partial_feedback(): void
    {
        $assignment = $this->completedAssignment();
        InAppNotification::creating(fn () => false);
        try {
            $this->submitFeedback($assignment, $assignment->project->client);
            $this->fail('Notification failure must abort feedback.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('project_ratings', 0);
        }
        InAppNotification::flushEventListeners();
        try {
            DB::transaction(function () use ($assignment): void {
                $this->submitFeedback($assignment, $assignment->freelancer);
                throw new RuntimeException('Abort outer work');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort outer work', $exception->getMessage());
        }
        $this->assertDatabaseCount('project_ratings', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_stale_status_role_and_counterparty_role_are_rechecked_on_save_with_retained_input(): void
    {
        $assignment = $this->completedAssignment();
        $this->actingAs($assignment->project->client);
        $page = Volt::test('projects.feedback', ['project' => $assignment->project])->set('rating', 5)->set('feedback', 'Retain these comments')->set('confirmed', true);
        DB::table('projects')->where('id', $assignment->project_id)->update(['status' => 'in_progress']);
        $page->call('submit')->assertHasErrors(['feedback'])->assertSet('rating', 5)->assertSet('feedback', 'Retain these comments');
        DB::table('projects')->where('id', $assignment->project_id)->update(['status' => 'completed']);
        $rolePage = Volt::test('projects.feedback', ['project' => $assignment->project]);
        DB::table('users')->where('id', $assignment->freelancer_id)->update(['role' => 'client']);
        $page->call('submit')->assertForbidden();
        DB::table('users')->where('id', $assignment->project->client_id)->update(['role' => 'admin']);
        $rolePage->call('submit')->assertForbidden();
        $this->assertDatabaseCount('project_ratings', 0);
    }

    public function test_self_rating_in_an_inconsistent_historical_assignment_is_denied(): void
    {
        $assignment = $this->completedAssignment();
        DB::table('projects')->where('id', $assignment->project_id)->update(['client_id' => $assignment->freelancer_id, 'completed_by' => $assignment->freelancer_id]);
        $this->actingAs($assignment->freelancer);
        Volt::test('projects.feedback', ['project' => $assignment->project->fresh()])->assertForbidden();
        $this->assertDatabaseCount('project_ratings', 0);
    }

    #[DataProvider('lockedFields')]
    public function test_project_identity_and_submission_identifier_are_locked(string $field, mixed $value): void
    {
        $assignment = $this->completedAssignment();
        $this->actingAs($assignment->freelancer);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('projects.feedback', ['project' => $assignment->project])->set($field, $value);
    }

    public static function parties(): array
    {
        return [[true], [false]];
    }

    public static function windowBoundaries(): array
    {
        return [[-1, false], [0, true], [1, true], [1209599, true], [1209600, false], [1209601, false]];
    }

    public static function unrelatedRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function ineligibleProjects(): array
    {
        return [[['status' => 'draft']], [['status' => 'open']], [['status' => 'in_progress']], [['status' => 'cancelled']], [['completed_at' => null]], [['completed_by' => null]], [['completed_by' => 999999]]];
    }

    public static function invalidFields(): array
    {
        return [['rating', 0], ['rating', 6], ['rating', -1], ['rating', 3.5], ['rating', 1.0], ['rating', '3.5'], ['rating', null], ['rating', ''], ['rating', true], ['rating', []], ['rating', '1e0'], ['rating', '01'], ['feedback', str_repeat('a', 2001)], ['feedback', []], ['confirmed', false]];
    }

    public static function optionalFeedback(): array
    {
        return [[null, null], ['', null], [" \n\t ", null], ["\u{2003}\u{00A0}", null], [str_repeat('x', 2000), str_repeat('x', 2000)]];
    }

    public static function conflictingRetries(): array
    {
        return [['rating', 5], ['feedback', 'Changed comments'], ['token', null]];
    }

    public static function independentHistory(): array
    {
        return [['none'], ['payment'], ['receipt'], ['dispute']];
    }

    public static function lockedFields(): array
    {
        return [['projectId', 999999], ['submissionId', '00000000-0000-4000-8000-000000000000']];
    }
}
