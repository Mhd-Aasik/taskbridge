<?php

namespace Tests\Feature;

use App\Actions\Payments\ReportPayment;
use App\Enums\ProjectStatus;
use App\Models\FreelancerProfile;
use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PaymentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-26 18:45:00', 'UTC'));
    }

    public function test_pending_view_and_refresh_do_not_create_records_for_either_participant(): void
    {
        $assignment = $this->assignment();
        foreach ([$assignment->project->client, $assignment->freelancer] as $actor) {
            $this->actingAs($actor)->get(route('projects.payment', $assignment->project_id))->assertOk()
                ->assertSeeText('Pending — no Client payment report')->assertSeeText('LKR 12,345.40');
            Volt::test('projects.payment', ['project' => $assignment->project])->call('refreshPayment')->assertHasNoErrors();
        }
        $this->assertDatabaseCount('payment_reports', 0);
        $this->assertDatabaseCount('payment_acknowledgments', 0);
    }

    public function test_guests_redirect_to_login_and_unassigned_project_is_not_a_payment_workspace(): void
    {
        $assignment = $this->assignment();
        $this->get(route('projects.payment', $assignment->project_id))->assertRedirect(route('login'));
        $project = Project::factory()->create();
        $this->actingAs($project->client)->get(route('projects.payment', $project))->assertNotFound();
    }

    #[DataProvider('unrelatedRoles')]
    public function test_unrelated_accounts_and_admin_have_no_read_access(string $role): void
    {
        $assignment = $this->assignment();
        PaymentReport::factory()->create(['project_assignment_id' => $assignment->id, 'note' => 'Private client note']);
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route('projects.payment', $assignment->project_id))->assertForbidden()->assertDontSeeText('Private client note');
        Volt::test('projects.payment', ['project' => $assignment->project])->assertForbidden();
    }

    public function test_client_form_displays_read_only_agreed_amount_and_accessible_confirmation(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $this->get(route('projects.payment', $assignment->project_id))->assertOk()
            ->assertSeeText('Report external payment')->assertSeeText('Read-only full agreed amount')->assertSeeText('LKR 12,345.40')
            ->assertSee('aria-labelledby="report-payment-heading"', false)->assertSee('aria-describedby="report-payment-explanation"', false)
            ->assertSeeText('Confirm payment report')->assertDontSeeText('Confirm receipt acknowledgment')
            ->assertDontSee('wire:model="amount"', false)->assertDontSee('wire:model="currency"', false);
        $form = Volt::test('projects.payment', ['project' => $assignment->project]);
        $form->call('recordPayment')->assertHasErrors(['reportConfirmed']);
        $form->set('reportConfirmed', true)->set('reportReference', '  Account reference  ')->set('reportNote', '  Paid outside TaskBridge.  ')
            ->call('recordPayment')->assertHasNoErrors()->assertSeeText('Paid — reported by the Client')->assertSeeText('Awaiting Freelancer acknowledgment');
        $this->assertSame('Account reference', PaymentReport::sole()->reference);
        $this->assertSame('Paid outside TaskBridge.', PaymentReport::sole()->note);
        $this->assertDatabaseCount('payment_acknowledgments', 0);
    }

    public function test_validation_keeps_client_input_and_stored_state_unchanged(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        Volt::test('projects.payment', ['project' => $assignment->project])->set('reportConfirmed', true)->set('reportPaidOn', '2026-10-28')
            ->set('reportNote', 'Keep my entered note')->call('recordPayment')->assertHasErrors(['reportPaidOn'])->assertSet('reportNote', 'Keep my entered note');
        $this->assertDatabaseCount('payment_reports', 0);
    }

    public function test_assigned_freelancer_has_only_receipt_action_and_client_cannot_acknowledge(): void
    {
        $assignment = $this->assignment();
        $report = PaymentReport::factory()->create(['project_assignment_id' => $assignment->id, 'reference' => 'Bank reference', 'method' => 'cash', 'paid_on' => '2026-10-27']);
        $this->actingAs($assignment->freelancer);
        $this->get(route('projects.payment', $assignment->project_id))->assertOk()->assertSeeText('Confirm receipt acknowledgment')->assertSeeText('Cash')
            ->assertSeeText('27 October 2026')->assertSeeText('Bank reference')->assertDontSeeText('Confirm payment report');
        $form = Volt::test('projects.payment', ['project' => $assignment->project]);
        $form->call('acknowledgeReceipt')->assertHasErrors(['receiptConfirmed']);
        $form->set('receiptConfirmed', true)->set('receiptNote', '  Full payment received.  ')->call('acknowledgeReceipt')->assertHasNoErrors()->assertSeeText('Acknowledged by the assigned Freelancer');
        $this->assertSame('Full payment received.', $report->fresh()->acknowledgment->note);
    }

    public function test_client_cannot_invoke_receipt_action_on_their_report(): void
    {
        $assignment = $this->assignment();
        PaymentReport::factory()->create(['project_assignment_id' => $assignment->id]);
        $this->actingAs($assignment->project->client);
        Volt::test('projects.payment', ['project' => $assignment->project])->set('receiptConfirmed', true)->call('acknowledgeReceipt')->assertForbidden();
        $this->assertDatabaseCount('payment_acknowledgments', 0);
    }

    public function test_freelancer_cannot_report_or_acknowledge_before_a_report_exists(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->freelancer);
        Volt::test('projects.payment', ['project' => $assignment->project])->set('receiptConfirmed', true)->call('acknowledgeReceipt')->assertHasErrors(['receipt']);
        Volt::test('projects.payment', ['project' => $assignment->project])->set('reportConfirmed', true)->call('recordPayment')->assertForbidden();
        $this->assertDatabaseCount('payment_reports', 0);
    }

    public function test_freelancer_refreshes_a_new_report_and_sees_details_before_confirmation(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->freelancer);
        $form = Volt::test('projects.payment', ['project' => $assignment->project]);
        $report = PaymentReport::factory()->create(['project_assignment_id' => $assignment->id, 'reference' => 'New report details']);
        $form->call('refreshPayment')->assertSet('reportId', $report->id)->assertSet('receiptConfirmed', false)->assertSeeText('New report details')->assertSeeText('Acknowledge receipt');
    }

    public function test_exact_page_retries_and_conflicting_notes_preserve_original_timestamps(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.payment', ['project' => $assignment->project])->set('reportConfirmed', true)->set('reportNote', 'Original payment note')->call('recordPayment')->assertHasNoErrors();
        $report = PaymentReport::sole()->fresh();
        $before = $report->getAttributes();
        $this->travel(1)->hours();
        $form->call('recordPayment')->assertHasNoErrors();
        $this->assertSame($before, $report->fresh()->getAttributes());
        $form->set('reportNote', 'Changed')->call('recordPayment')->assertHasErrors(['payment'])->assertSet('reportNote', 'Changed');
        $this->assertSame($before, $report->fresh()->getAttributes());
        $this->actingAs($assignment->freelancer);
        $receiptForm = Volt::test('projects.payment', ['project' => $assignment->project])->set('receiptConfirmed', true)->set('receiptNote', 'Original receipt note')->call('acknowledgeReceipt')->assertHasNoErrors();
        $receipt = PaymentAcknowledgment::sole()->fresh();
        $receiptBefore = $receipt->getAttributes();
        $this->travel(1)->hours();
        $receiptForm->call('acknowledgeReceipt')->assertHasNoErrors();
        $receiptForm->set('receiptNote', 'Changed')->call('acknowledgeReceipt')->assertHasErrors(['receipt']);
        $this->assertSame($receiptBefore, $receipt->fresh()->getAttributes());
    }

    public function test_response_failure_after_commit_keeps_a_retryable_report_and_entered_values(): void
    {
        $assignment = $this->assignment();
        $realAction = new ReportPayment;
        $mock = Mockery::mock(ReportPayment::class)->makePartial();
        $mock->shouldReceive('report')->once()->andReturnUsing(function (...$arguments) use ($realAction): never {
            $realAction->report(...$arguments);
            throw new RuntimeException('Simulated response failure after commit');
        });
        $this->app->instance(ReportPayment::class, $mock);
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.payment', ['project' => $assignment->project])->set('reportConfirmed', true)->set('reportNote', 'Kept after failure')->call('recordPayment')
            ->assertHasErrors(['payment'])->assertSet('reportNote', 'Kept after failure');
        $before = PaymentReport::sole()->getAttributes();
        $this->app->instance(ReportPayment::class, $realAction);
        $form->call('recordPayment')->assertHasNoErrors();
        $this->assertSame($before, PaymentReport::sole()->getAttributes());
        $this->assertDatabaseCount('payment_reports', 1);
    }

    public function test_a_stale_client_form_rechecks_project_state_and_role(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $form = Volt::test('projects.payment', ['project' => $assignment->project])->set('reportConfirmed', true)->set('reportNote', 'Preserve this');
        DB::table('projects')->where('id', $assignment->project_id)->update(['status' => ProjectStatus::Cancelled->value]);
        $form->call('recordPayment')->assertHasErrors(['payment'])->assertSet('reportNote', 'Preserve this');
        DB::table('users')->where('id', $assignment->project->client_id)->update(['role' => User::ROLE_ADMIN]);
        $form->call('recordPayment')->assertForbidden();
        $this->assertDatabaseCount('payment_reports', 0);
    }

    public function test_report_identity_is_locked_against_nested_identifier_tampering(): void
    {
        $report = PaymentReport::factory()->create();
        $this->actingAs($report->assignment->freelancer);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('projects.payment', ['project' => $report->assignment->project])->set('reportId', 999999);
    }

    public function test_submission_identifier_is_locked_against_client_tampering(): void
    {
        $assignment = $this->assignment();
        $this->actingAs($assignment->project->client);
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('projects.payment', ['project' => $assignment->project])->set('submissionId', '00000000-0000-4000-8000-000000000000');
    }

    public function test_both_parties_see_escaped_notes_local_times_and_no_private_counterparty_data(): void
    {
        $assignment = $this->assignment();
        $client = $assignment->project->client;
        $freelancer = $assignment->freelancer;
        $client->forceFill(['avatar_path' => 'avatars/private-client-photo.png'])->save();
        $freelancer->forceFill(['avatar_path' => 'avatars/private-freelancer-photo.png'])->save();
        FreelancerProfile::factory()->create(['user_id' => $freelancer->id, 'headline' => 'Private headline not for payment review', 'is_public' => false]);
        $report = PaymentReport::factory()->create(['project_assignment_id' => $assignment->id, 'reference' => '<img src=x onerror=alert(1)>', 'note' => "First line\n<script>alert(1)</script>"]);
        PaymentAcknowledgment::factory()->create(['payment_report_id' => $report->id, 'note' => "Received\n<script>alert(2)</script>"]);
        foreach ([$client, $freelancer] as $actor) {
            $counterparty = $actor->isClient() ? $freelancer : $client;
            $this->actingAs($actor)->get(route('projects.payment', $assignment->project_id))->assertOk()->assertSeeText('27 October 2026, 12:15 am')
                ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false)
                ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x onerror=alert(1)>', false)
                ->assertDontSee($counterparty->email)->assertDontSee($counterparty->avatar_path)->assertDontSee('Private headline not for payment review')
                ->assertDontSee($report->submission_id)->assertSeeText('Acknowledged by the assigned Freelancer');
        }
    }

    public function test_completed_projects_keep_payment_access_actions_links_and_dashboard_summaries(): void
    {
        $assignment = $this->assignment();
        $assignment->project->forceFill(['status' => ProjectStatus::Completed, 'completed_at' => now(), 'completed_by' => $assignment->project->client_id])->save();
        $this->actingAs($assignment->project->client);
        Volt::test('projects.payment', ['project' => $assignment->project])->set('reportConfirmed', true)->call('recordPayment')->assertHasNoErrors();
        $this->get(route('client.projects.show', $assignment->project_id))->assertOk()->assertSee(route('projects.payment', $assignment->project_id), false)->assertSeeText('Paid — reported by the Client');
        $this->get(route('client.dashboard'))->assertOk()->assertSeeText('Paid — reported by the Client');
        $this->actingAs($assignment->freelancer);
        Volt::test('projects.payment', ['project' => $assignment->project])->set('receiptConfirmed', true)->call('acknowledgeReceipt')->assertHasNoErrors();
        $this->get(route('freelancer.assignments.show', $assignment))->assertOk()->assertSee(route('projects.payment', $assignment->project_id), false)->assertSeeText('Receipt acknowledged by the assigned Freelancer.');
        $this->get(route('freelancer.dashboard'))->assertOk()->assertSeeText('Receipt acknowledged by the assigned Freelancer.');
        $this->assertSame(ProjectStatus::Completed, $assignment->project->fresh()->status);
    }

    private function assignment(): ProjectAssignment
    {
        return ProjectAssignment::factory()->create(['assigned_at' => '2026-10-25 20:00:00', 'agreed_budget' => '12345.40']);
    }

    public static function unrelatedRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }
}
