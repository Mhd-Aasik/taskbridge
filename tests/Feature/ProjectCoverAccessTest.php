<?php

namespace Tests\Feature;

use App\Actions\Projects\SaveProjectDraft;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ProjectRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class ProjectCoverAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC'));
        Storage::fake('project_covers');
        Storage::fake('public');
    }

    public function test_guests_cannot_fetch_project_covers_even_with_conditional_headers(): void
    {
        $project = $this->withCover();
        $this->withHeaders(['If-None-Match' => '*', 'If-Modified-Since' => 'Wed, 01 Jan 2098 00:00:00 GMT'])
            ->get($project->coverUrl())->assertRedirectToRoute('login');
    }

    #[DataProvider('projectStatuses')]
    public function test_owning_client_can_read_covers_in_every_project_status(ProjectStatus $status): void
    {
        $project = $this->withCover(['status' => $status]);
        $before = $project->getAttributes();
        $response = $this->actingAs($project->client)->get($project->coverUrl())->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
        $bytes = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent();
        $this->assertSame(Storage::disk('project_covers')->get($project->cover_path), $bytes);
        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    #[DataProvider('nonOwnerRoles')]
    public function test_unrelated_clients_and_admins_have_no_implicit_cover_access(string $role): void
    {
        $project = $this->withCover(['status' => ProjectStatus::Open, 'published_at' => now()->subHour()]);
        $this->actingAs(User::factory()->create(['role' => $role]))->get($project->coverUrl())->assertForbidden();
    }

    public function test_freelancer_can_read_discoverable_cover_without_proposal_or_assignment(): void
    {
        $project = $this->withCover(['status' => ProjectStatus::Open, 'published_at' => now()->subHour(), 'deadline' => '2026-10-23']);
        $freelancer = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $before = $project->getAttributes();
        $this->actingAs($freelancer)->get($project->coverUrl())->assertOk();
        $this->assertDatabaseCount('proposals', 0);
        $this->assertDatabaseCount('project_assignments', 0);
        $this->assertSame($before, $project->fresh()->getAttributes());
    }

    #[DataProvider('ineligibleCases')]
    public function test_ineligible_project_covers_do_not_leak_through_guessed_urls(string $case): void
    {
        $project = $this->ineligible($case);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]))
            ->get(route('projects.cover', $project))->assertForbidden();
    }

    #[DataProvider('ineligibleCases')]
    public function test_own_proposal_history_preserves_cover_access_after_discovery_closes(string $case): void
    {
        $project = $this->ineligible($case);
        $proposal = Proposal::factory()->create(['project_id' => $project->id, 'status' => ProposalStatus::Withdrawn]);
        $before = $project->fresh()->getAttributes();
        $this->actingAs($proposal->freelancer)->get($project->coverUrl())->assertOk();
        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertSame(ProposalStatus::Withdrawn, $proposal->fresh()->status);
    }

    #[DataProvider('proposalStatuses')]
    public function test_each_own_proposal_status_retains_private_cover_history(ProposalStatus $status): void
    {
        $project = $this->withCover(['status' => ProjectStatus::Completed]);
        $proposal = Proposal::factory()->create(['project_id' => $project->id, 'status' => $status]);
        $this->actingAs($proposal->freelancer)->get($project->coverUrl())->assertOk();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]))
            ->get($project->coverUrl())->assertForbidden();
    }

    public function test_assignment_identity_can_authorize_history_without_an_own_proposal(): void
    {
        $freelancer = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $assignment = ProjectAssignment::factory()->create(['freelancer_id' => $freelancer->id]);
        $project = $this->attachCover($assignment->project);
        $project->forceFill(['status' => ProjectStatus::Completed])->save();
        $this->assertFalse($freelancer->submittedProposals()->where('project_id', $project->id)->exists());
        $this->actingAs($freelancer)->get($project->coverUrl())->assertOk();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]))
            ->get($project->coverUrl())->assertForbidden();
    }

    public function test_current_persisted_roles_are_checked_for_clients_and_historical_freelancers(): void
    {
        $project = $this->withCover();
        $proposal = Proposal::factory()->create(['project_id' => $project->id]);
        foreach ([$project->client, $proposal->freelancer] as $user) {
            $this->actingAs($user);
            DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_ADMIN]);
            $this->get($project->coverUrl())->assertForbidden();
        }
    }

    public function test_project_ownership_is_resolved_from_persisted_data_not_the_authenticated_models_cache(): void
    {
        $project = $this->withCover();
        $this->actingAs($project->client);
        DB::table('projects')->where('id', $project->id)->update(['client_id' => User::factory()->create(['role' => User::ROLE_CLIENT])->id]);
        $this->get($project->coverUrl())->assertForbidden();
    }

    public function test_private_image_headers_and_conditional_requests_reauthorize_before_304(): void
    {
        $project = $this->withCover();
        $this->actingAs($project->client);
        $response = $this->get($project->coverUrl())->assertOk()->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Vary', 'Cookie')
            ->assertHeader('ETag')->assertHeader('Last-Modified');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $etag = $response->headers->get('ETag');
        $this->withHeaders(['If-None-Match' => $etag])->get($project->coverUrl())->assertStatus(304);
        $this->withHeaders(['If-Modified-Since' => $response->headers->get('Last-Modified')])->get($project->coverUrl())->assertStatus(304);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]))
            ->withHeaders(['If-None-Match' => $etag])->get($project->coverUrl())->assertForbidden();
    }

    public function test_cache_busting_uses_an_authenticated_url_without_leaking_storage_paths(): void
    {
        $project = $this->withCover();
        $first = $project->coverUrl();
        $this->assertStringStartsWith(route('projects.cover', $project).'?v=', $first);
        $this->assertStringNotContainsString($project->cover_path, $first);
        $this->assertStringNotContainsString('storage/', $first);
        Storage::disk('public')->assertMissing($project->cover_path);
        $this->attachCover($project);
        $this->assertNotSame($first, $project->fresh()->coverUrl());
        $this->assertSame('private', config('filesystems.disks.project_covers.visibility'));
        $this->assertFalse(config('filesystems.disks.project_covers.serve'));
    }

    public function test_missing_covers_and_files_return_not_found_without_weakening_authorization(): void
    {
        $project = Project::factory()->create();
        $this->assertNull($project->coverUrl());
        $this->actingAs($project->client)->get(route('projects.cover', $project))->assertNotFound();
        $this->attachCover($project);
        Storage::disk('project_covers')->delete($project->cover_path);
        $this->get($project->coverUrl())->assertNotFound();
        $this->get(route('projects.cover', 999999))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CLIENT]))
            ->get(route('projects.cover', $project))->assertForbidden();
    }

    public function test_paths_cannot_reference_another_projects_cover_or_a_traversal_path(): void
    {
        $project = $this->withCover();
        $other = Project::factory()->create(['client_id' => $project->client_id]);
        $this->actingAs($project->client);
        foreach ([$project->cover_path, '../'.$project->cover_path, 'arbitrary.webp'] as $path) {
            $other->forceFill(['cover_path' => $path])->save();
            $this->assertNull($other->coverUrl());
            $this->get(route('projects.cover', $other))->assertNotFound();
        }
        Storage::disk('project_covers')->assertExists($project->cover_path);
    }

    public function test_sri_lankan_deadline_boundary_is_applied_to_cover_access(): void
    {
        $project = $this->withCover(['status' => ProjectStatus::Open, 'published_at' => now()->subDay(), 'deadline' => '2026-10-23']);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_FREELANCER]));
        $this->travelTo(CarbonImmutable::parse('2026-10-23 18:29:59', 'UTC'));
        $this->get($project->coverUrl())->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 18:30:00', 'UTC'));
        $this->get($project->coverUrl())->assertForbidden();
    }

    public function test_cover_component_renders_in_authorized_workspaces_without_exposing_paths_or_unescaped_titles(): void
    {
        $project = $this->withCover(['title' => '<script>project title</script>', 'status' => ProjectStatus::Open, 'published_at' => now()->subHour()]);
        $proposal = Proposal::factory()->create(['project_id' => $project->id]);
        $this->actingAs($project->client);
        foreach ([route('client.dashboard'), route('client.projects.index'), route('client.projects.show', $project),
            route('client.projects.proposals.index', $project), route('client.projects.proposals.show', [$project, $proposal])] as $url) {
            $this->get($url)->assertOk()->assertSee($project->coverUrl())->assertDontSee($project->cover_path)
                ->assertDontSee('<script>project title</script>', false)->assertSee('&lt;script&gt;project title&lt;/script&gt;', false);
        }
        $this->actingAs($proposal->freelancer);
        foreach ([route('freelancer.projects.index'), route('freelancer.projects.show', $project),
            route('freelancer.proposals.index'), route('freelancer.proposals.show', $proposal)] as $url) {
            $this->get($url)->assertOk()->assertSee($project->coverUrl())->assertDontSee($project->cover_path)
                ->assertDontSee($project->client->email)->assertDontSee('<script>project title</script>', false);
        }
        $assignment = ProjectAssignment::factory()->create();
        $assignedProject = $this->attachCover($assignment->project);
        $this->actingAs($assignment->freelancer);
        foreach ([route('freelancer.dashboard'), route('freelancer.assignments.index'), route('freelancer.assignments.show', $assignment)] as $url) {
            $this->get($url)->assertOk()->assertSee($assignedProject->coverUrl())->assertDontSee($assignedProject->cover_path);
        }
    }

    public function test_missing_file_component_has_a_decorative_fallback_and_no_image_keeps_the_same_layout(): void
    {
        $project = $this->withCover();
        Storage::disk('project_covers')->delete($project->cover_path);
        $this->actingAs($project->client)->get(route('client.projects.show', $project))->assertOk()
            ->assertSee('imageFailed = true', false)->assertSee('x-show="! imageFailed"', false)
            ->assertSee('aria-hidden="true"', false)->assertSee('alt=""', false)->assertSee('object-cover', false);
        $project->forceFill(['cover_path' => null])->save();
        $this->get(route('client.projects.show', $project))->assertOk()->assertDontSee(route('projects.cover', $project))
            ->assertSee('aspect-[16/7]', false)->assertSee('from-teal-50', false);
    }

    public function test_replacement_invalidates_old_etag_and_old_version_urls_even_with_same_second_modified_time(): void
    {
        $project = $this->withCover();
        $project->forceFill(['deadline' => '2026-11-01'])->save();
        $this->actingAs($project->client);
        $oldUrl = $project->coverUrl();
        $response = $this->get($oldUrl)->assertOk();
        $image = imagecreatetruecolor(120, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 20, 80));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $saved = app(SaveProjectDraft::class)->save($project->id, ProjectRules::savedDetails($project), UploadedFile::fake()->createWithContent('new.png', $bytes));
        $this->assertNotSame($oldUrl, $saved->coverUrl());
        $new = $this->withHeaders(['If-None-Match' => $response->headers->get('ETag')])->get($saved->coverUrl())->assertOk();
        $this->assertNotSame($response->headers->get('ETag'), $new->headers->get('ETag'));
        $this->withHeaders(['If-Modified-Since' => $response->headers->get('Last-Modified')])->get($oldUrl)->assertOk();
    }

    private function withCover(array $attributes = []): Project
    {
        return $this->attachCover(Project::factory()->create($attributes));
    }

    private function attachCover(Project $project): Project
    {
        $path = 'projects/'.$project->id.'/'.Str::uuid().'.webp';
        $image = imagecreatetruecolor(24, 12);
        ob_start();
        imagewebp($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        Storage::disk('project_covers')->put($path, $bytes);
        $project->forceFill(['cover_path' => $path])->save();

        return $project->fresh();
    }

    private function ineligible(string $case): Project
    {
        $project = $this->withCover(['status' => ProjectStatus::Open, 'published_at' => now()->subHour(), 'deadline' => '2026-10-25']);
        match ($case) {
            'draft' => $project->forceFill(['status' => ProjectStatus::Draft])->save(),
            'in_progress' => $project->forceFill(['status' => ProjectStatus::InProgress])->save(),
            'completed' => $project->forceFill(['status' => ProjectStatus::Completed])->save(),
            'cancelled' => $project->forceFill(['status' => ProjectStatus::Cancelled])->save(),
            'unpublished' => $project->forceFill(['published_at' => null])->save(),
            'future' => $project->forceFill(['published_at' => now()->addMinute()])->save(),
            'inactive category' => $project->category->forceFill(['is_active' => false])->save(),
            'expired' => $project->forceFill(['deadline' => '2026-10-22'])->save(),
        };

        return $project->fresh();
    }

    public static function projectStatuses(): array
    {
        return array_map(fn ($status) => [$status], ProjectStatus::cases());
    }

    public static function proposalStatuses(): array
    {
        return array_map(fn ($status) => [$status], ProposalStatus::cases());
    }

    public static function nonOwnerRoles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_ADMIN]];
    }

    public static function ineligibleCases(): array
    {
        return array_map(fn ($case) => [$case], ['draft', 'in_progress', 'completed', 'cancelled', 'unpublished', 'future', 'inactive category', 'expired']);
    }
}
