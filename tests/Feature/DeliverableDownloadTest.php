<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliverableDownloadTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENTS = "TaskBridge project handover\nPrivate delivery notes.\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('deliverables');
    }

    #[DataProvider('projectStatuses')]
    public function test_both_participants_can_download_current_and_historical_versions_without_mutation(ProjectStatus $status): void
    {
        $version = $this->storedVersion();
        $deliverable = $version->deliverable;
        $second = $this->storedVersion(['deliverable_id' => $deliverable->id]);
        $assignment = $deliverable->assignment;
        $project = $assignment->project;
        $project->forceFill(['status' => $status])->save();
        $projectBefore = $project->fresh()->getAttributes();
        $versionBefore = $version->fresh()->getAttributes();

        foreach ([$project->client, $assignment->freelancer] as $participant) {
            $this->actingAs($participant);
            foreach ([$version, $second] as $download) {
                $response = $this->get($this->url($download))->assertOk()
                    ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
                    ->assertHeader('X-Content-Type-Options', 'nosniff')
                    ->assertHeader('Vary', 'Cookie')
                    ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
                $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
                $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
                $this->assertStringContainsString('max-age=0', $response->headers->get('Cache-Control'));
                $this->assertSame(self::CONTENTS, $response->streamedContent());
                $this->assertStringNotContainsString($download->storage_path, (string) $response->headers);
                $this->assertStringNotContainsString(storage_path(), (string) $response->headers);
            }
        }

        $this->assertSame($projectBefore, $project->fresh()->getAttributes());
        $this->assertSame($versionBefore, $version->fresh()->getAttributes());
        $this->assertDatabaseCount('deliverable_versions', 2);
        $this->assertDatabaseCount('deliverables', 1);
        $this->assertCount(2, Storage::disk('deliverables')->allFiles());
    }

    public function test_guest_download_redirects_to_login_without_exposing_file_contents(): void
    {
        $version = $this->storedVersion();
        $this->get($this->url($version))->assertRedirect(route('login'))->assertDontSee(self::CONTENTS);
        Storage::disk('deliverables')->assertExists($version->storage_path);
        $this->assertDatabaseCount('deliverable_versions', 1);
    }

    #[DataProvider('roles')]
    public function test_unrelated_accounts_cannot_download_private_files(string $role): void
    {
        $version = $this->storedVersion();
        $outsider = User::factory()->create(['role' => $role]);
        $this->actingAs($outsider)->get($this->url($version))->assertForbidden()->assertDontSee(self::CONTENTS)
            ->assertDontSee($version->storage_path);
    }

    public function test_persisted_role_changes_revoke_both_participants_download_access(): void
    {
        $version = $this->storedVersion();
        $assignment = $version->deliverable->assignment;
        foreach ([$assignment->project->client, $assignment->freelancer] as $participant) {
            $this->actingAs($participant);
            DB::table('users')->where('id', $participant->id)->update(['role' => User::ROLE_ADMIN]);
            $this->get($this->url($version))->assertForbidden()->assertDontSee(self::CONTENTS);
        }
    }

    public function test_reassigned_history_uses_persisted_assignment_instead_of_original_uploader(): void
    {
        $version = $this->storedVersion();
        $assignment = $version->deliverable->assignment;
        $oldFreelancer = $assignment->freelancer;
        $replacement = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $this->actingAs($oldFreelancer);
        // Simulate an externally changed historical assignment, without exposing a reassignment action.
        DB::table('project_assignments')->where('id', $assignment->id)->update(['freelancer_id' => $replacement->id]);

        $this->get($this->url($version))->assertForbidden();
        $response = $this->actingAs($replacement)->get($this->url($version))->assertOk();
        $this->assertSame(self::CONTENTS, $response->streamedContent());
    }

    public function test_changed_project_ownership_revokes_original_client_access(): void
    {
        $version = $this->storedVersion();
        $project = $version->deliverable->assignment->project;
        $oldClient = $project->client;
        $newClient = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($oldClient);
        DB::table('projects')->where('id', $project->id)->update(['client_id' => $newClient->id]);

        $this->get($this->url($version))->assertForbidden();
        $response = $this->actingAs($newClient)->get($this->url($version))->assertOk();
        $this->assertSame(self::CONTENTS, $response->streamedContent());
    }

    public function test_mismatched_project_deliverable_and_version_urls_are_not_found(): void
    {
        $first = $this->storedVersion();
        $firstDeliverable = $first->deliverable;
        $assignment = $firstDeliverable->assignment;
        $secondDeliverable = Deliverable::factory()->create(['project_assignment_id' => $assignment->id]);
        $second = $this->storedVersion(['deliverable_id' => $secondDeliverable->id]);
        $otherProjectVersion = $this->storedVersion();
        $this->actingAs($assignment->freelancer);

        foreach ([
            ['project' => $assignment->project_id, 'deliverable' => $firstDeliverable->id, 'version' => $second->id],
            ['project' => $assignment->project_id, 'deliverable' => $secondDeliverable->id, 'version' => $first->id],
            ['project' => $assignment->project_id, 'deliverable' => $otherProjectVersion->deliverable_id, 'version' => $otherProjectVersion->id],
            ['project' => $assignment->project_id, 'deliverable' => $firstDeliverable->id, 'version' => 999999],
            ['project' => $assignment->project_id, 'deliverable' => 999999, 'version' => $first->id],
        ] as $parameters) {
            $this->get(route('projects.deliverables.download', $parameters))->assertNotFound()->assertDontSee(self::CONTENTS);
        }
    }

    public function test_authorized_requests_for_a_project_without_an_assignment_are_not_found(): void
    {
        $version = $this->storedVersion();
        $client = $version->deliverable->assignment->project->client;
        $project = Project::factory()->create(['client_id' => $client->id]);
        $this->actingAs($client)->get(route('projects.deliverables.download', [
            'project' => $project->id, 'deliverable' => $version->deliverable_id, 'version' => $version->id,
        ]))->assertNotFound();
    }

    public function test_missing_private_file_returns_not_found_without_exposing_its_path(): void
    {
        $version = DeliverableVersion::factory()->create();
        $this->actingAs($version->deliverable->assignment->freelancer)->get($this->url($version))
            ->assertNotFound()->assertDontSee($version->storage_path)->assertDontSee(storage_path());
        $this->assertModelExists($version);
    }

    #[DataProvider('forgedPaths')]
    public function test_forged_metadata_cannot_deliver_arbitrary_files(string $path): void
    {
        $version = $this->storedVersion();
        $path = str_replace('{uuid}', (string) Str::uuid(), $path);
        DB::table('deliverable_versions')->where('id', $version->id)->update(['storage_path' => $path]);
        $this->actingAs($version->deliverable->assignment->freelancer)->get($this->url($version))
            ->assertNotFound()->assertDontSee($path)->assertDontSee(self::CONTENTS);
    }

    public function test_download_filename_is_safe_and_preserves_a_unicode_display_name(): void
    {
        $version = $this->storedVersion(['original_filename' => "..\\private/අවසන් résumé\r\nX-Evil: injected.txt"]);
        $response = $this->actingAs($version->deliverable->assignment->project->client)->get($this->url($version))->assertOk();
        $disposition = $response->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString("filename*=utf-8''", $disposition);
        $this->assertStringContainsString(rawurlencode('අවසන්'), $disposition);
        $this->assertStringNotContainsString('\r', $disposition);
        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringNotContainsString('private/', $disposition);
        $this->assertStringNotContainsString('..', $disposition);
        $this->assertFalse($response->headers->has('X-Evil'));
        $this->assertSame(self::CONTENTS, $response->streamedContent());
    }

    public function test_download_uses_the_validated_extension_instead_of_an_unsafe_original_name(): void
    {
        $version = $this->storedVersion(['original_filename' => 'release.exe']);
        $response = $this->actingAs($version->deliverable->assignment->freelancer)->get($this->url($version))->assertOk();
        $this->assertStringContainsString('release.txt', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('.exe', $response->headers->get('Content-Disposition'));
        $this->assertSame(self::CONTENTS, $response->streamedContent());
    }

    private function storedVersion(array $attributes = []): DeliverableVersion
    {
        $version = DeliverableVersion::factory()->create($attributes + [
            'original_filename' => 'handover.txt', 'byte_size' => strlen(self::CONTENTS), 'sha256' => hash('sha256', self::CONTENTS),
        ]);
        Storage::disk('deliverables')->put($version->storage_path, self::CONTENTS);

        return $version;
    }

    private function url(DeliverableVersion $version): string
    {
        return route('projects.deliverables.download', [
            'project' => $version->deliverable->assignment->project_id,
            'deliverable' => $version->deliverable_id, 'version' => $version->id,
        ]);
    }

    public static function projectStatuses(): array
    {
        return array_map(fn (ProjectStatus $status) => [$status], ProjectStatus::cases());
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function forgedPaths(): array
    {
        return [
            ['../private/secret.txt'], ['users/1/{uuid}.txt'], ['deliverables/999999/{uuid}.txt'],
            ['deliverables/1/not-a-uuid.txt'], ['deliverables/1/{uuid}.php'],
        ];
    }
}
