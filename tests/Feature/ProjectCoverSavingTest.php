<?php

namespace Tests\Feature;

use App\Actions\Projects\SaveProjectDraft;
use App\Enums\ProjectStatus;
use App\Models\Category;
use App\Models\FreelancerProfile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ProjectCovers;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Volt\Volt;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProjectCoverSavingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC'));
        Storage::fake('project_covers');
        Storage::fake('local');
        // Simulated future dates must not expire just-written files using OS timestamps.
        config(['livewire.temporary_file_upload.cleanup' => false]);
    }

    public function test_a_cover_is_optional_and_opening_the_editor_creates_nothing(): void
    {
        $this->client();
        $input = $this->details();
        $this->get(route('client.projects.create'))->assertOk()->assertSeeText('Project cover (optional)');
        $this->assertDatabaseCount('projects', 0);
        $project = app(SaveProjectDraft::class)->save(null, $input);
        $this->assertNull($project->cover_path);
        $this->assertNull($project->coverUrl());
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertNull($project->published_at);
        $this->assertSame([], Storage::disk('project_covers')->allFiles());
    }

    public function test_new_draft_cover_preview_is_private_and_saved_with_generated_path(): void
    {
        $user = $this->client();
        $component = $this->form()->set('cover', $this->image())->assertSet('previewReady', true)
            ->assertSee('URL.createObjectURL', false)->assertDontSee('/livewire/preview-file/', false);
        $this->assertDatabaseCount('projects', 0);
        $this->assertSame([], Storage::disk('project_covers')->allFiles());
        $component->call('save')->assertHasNoErrors()->assertSet('cover', null)->assertSet('previewReady', false);
        $project = $user->projects()->sole();
        $this->assertTrue(ProjectCovers::ownsPath($project, $project->cover_path));
        $this->assertSame('image/webp', Storage::disk('project_covers')->mimeType($project->cover_path));
        $this->assertSame(ProjectStatus::Draft, $project->status);
        $this->assertNull($project->published_at);
        $this->get(route('client.projects.edit', $project))->assertOk()->assertSeeText('Saved cover')
            ->assertSee($project->coverUrl())->assertDontSee($project->cover_path);
    }

    public function test_replacement_and_removal_change_only_cover_and_clean_superseded_files(): void
    {
        $this->client();
        $project = $this->covered();
        $oldPath = $project->cover_path;
        $oldUrl = $project->coverUrl();
        $component = Volt::test('client.projects.editor', ['project' => $project])->set('cover', $this->image(220))
            ->call('save')->assertHasNoErrors();
        $project->refresh();
        $this->assertNotSame($oldPath, $project->cover_path);
        $this->assertNotSame($oldUrl, $project->coverUrl());
        Storage::disk('project_covers')->assertMissing($oldPath);
        Storage::disk('project_covers')->assertExists($project->cover_path);
        $replacedPath = $project->cover_path;
        $component->call('save')->assertHasNoErrors();
        $this->assertSame([$replacedPath], Storage::disk('project_covers')->allFiles());
        Volt::test('client.projects.editor', ['project' => $project])->set('removeCover', true)
            ->assertSeeText('The saved cover will be removed when you save.')->call('save')->assertHasNoErrors();
        $this->assertNull($project->fresh()->cover_path);
        Storage::disk('project_covers')->assertMissing($replacedPath);
        $this->assertSame([], Storage::disk('project_covers')->allFiles());
    }

    public function test_identical_image_retries_preserve_current_path_without_orphans(): void
    {
        $this->client();
        $project = $this->covered();
        $before = $project->fresh()->getAttributes();
        $this->travel(1)->minutes();
        $saved = app(SaveProjectDraft::class)->save($project->id, $this->details($project), $this->image());
        $this->assertSame($before, $saved->fresh()->getAttributes());
        $this->assertSame([$project->cover_path], Storage::disk('project_covers')->allFiles());
    }

    public function test_discarding_preview_does_not_remove_the_saved_cover(): void
    {
        $this->client();
        $project = $this->covered();
        Volt::test('client.projects.editor', ['project' => $project])->set('cover', $this->image(220))
            ->call('clearCover')->assertSet('cover', null)->assertSet('previewReady', false)
            ->assertDispatched('project-cover-reset');
        $this->assertSame($project->cover_path, $project->fresh()->cover_path);
        $this->assertSame([$project->cover_path], Storage::disk('project_covers')->allFiles());
    }

    public function test_invalid_details_and_invalid_image_preserve_saved_details_cover_and_retained_input(): void
    {
        $this->client();
        $project = $this->covered();
        $before = $project->fresh()->getAttributes();
        Volt::test('client.projects.editor', ['project' => $project])->set('cover', $this->image(220))
            ->set('form.title', '')->call('save')->assertHasErrors(['form.title' => 'required'])->assertSet('previewReady', true);
        Volt::test('client.projects.editor', ['project' => $project])->set('form.title', 'Retain this title')
            ->set('cover', UploadedFile::fake()->createWithContent('cover.png', 'not an image'))
            ->assertHasErrors('cover')->assertSet('previewReady', false)->call('save')->assertHasErrors('cover')
            ->assertSet('form.title', 'Retain this title');
        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertSame([$project->cover_path], Storage::disk('project_covers')->allFiles());
    }

    #[DataProvider('otherRoles')]
    public function test_wrong_roles_cannot_save_even_if_historically_the_owner(string $role): void
    {
        $user = $this->client();
        $project = $this->covered();
        DB::table('users')->where('id', $user->id)->update(['role' => $role]);
        $this->expectException(AuthorizationException::class);
        app(SaveProjectDraft::class)->save($project->id, $this->details($project), $this->image(220));
    }

    public function test_other_client_and_guests_cannot_save_a_cover(): void
    {
        $this->client();
        $project = $this->covered();
        $this->client();
        Volt::test('client.projects.editor', ['project' => $project])->assertForbidden();
        try {
            app(SaveProjectDraft::class)->save($project->id, $this->details($project), $this->image(220));
            $this->fail('Cross-client save must be denied.');
        } catch (AuthorizationException) {
            $this->assertSame([$project->cover_path], Storage::disk('project_covers')->allFiles());
        }
        Auth::logout();
        Volt::test('client.projects.editor')->assertForbidden();
        $this->get(route('client.projects.create'))->assertRedirectToRoute('login');
    }

    public function test_stale_editor_cannot_replace_or_remove_after_publication(): void
    {
        $this->client();
        $project = $this->covered();
        $component = Volt::test('client.projects.editor', ['project' => $project])->set('cover', $this->image(220));
        $project->forceFill(['status' => ProjectStatus::Open, 'published_at' => now()])->save();
        $component->call('save')->assertForbidden();
        $this->get(route('client.projects.edit', $project))->assertForbidden();
        $this->assertSame([$project->cover_path], Storage::disk('project_covers')->allFiles());
        $this->assertSame($project->cover_path, $project->fresh()->cover_path);
    }

    public function test_untrusted_input_cannot_change_path_ownership_state_or_account_data(): void
    {
        $user = $this->client();
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id, 'is_public' => false]);
        $project = $this->covered();
        $project->fill(['cover_path' => 'arbitrary.webp']);
        $this->assertNotSame('arbitrary.webp', $project->cover_path);
        $saved = app(SaveProjectDraft::class)->save($project->id, $this->details($project) + [
            'cover_path' => 'arbitrary.webp', 'client_id' => 999, 'status' => 'open', 'published_at' => now(), 'role' => 'admin', 'is_public' => true,
        ]);
        $this->assertSame($project->cover_path, $saved->cover_path);
        $this->assertArrayNotHasKey('cover_path', $saved->toArray());
        $this->assertSame($user->id, $saved->client_id);
        $this->assertSame(ProjectStatus::Draft, $saved->status);
        $this->assertNull($saved->published_at);
        $this->assertSame(User::ROLE_CLIENT, $user->fresh()->role);
        $this->assertFalse($profile->fresh()->is_public);
    }

    public function test_preview_readiness_is_server_locked(): void
    {
        $this->client();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Volt::test('client.projects.editor')->set('previewReady', true);
    }

    public function test_preview_actions_recheck_authentication_and_the_persisted_client_role(): void
    {
        $this->client();
        $component = $this->form();
        Auth::logout();
        $component->set('cover', $this->image())->assertForbidden();
        $user = $this->client();
        $component = $this->form();
        DB::table('users')->where('id', $user->id)->update(['role' => User::ROLE_FREELANCER]);
        $component->set('cover', $this->image())->assertForbidden();
        $this->assertDatabaseCount('projects', 0);
        $this->assertSame([], Storage::disk('project_covers')->allFiles());
    }

    public function test_upload_and_removal_together_are_rejected(): void
    {
        $this->client();
        $project = $this->covered();
        try {
            app(SaveProjectDraft::class)->save($project->id, $this->details($project), $this->image(220), true);
            $this->fail('Conflicting cover operations must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cover', $exception->errors());
        }
        $this->assertSame([$project->cover_path], Storage::disk('project_covers')->allFiles());
    }

    public function test_database_failure_rolls_back_details_and_cleans_up_the_replacement(): void
    {
        $this->client();
        $project = $this->covered();
        $before = $project->fresh()->getAttributes();
        Project::saving(function (Project $saving): void {
            if ($saving->isDirty('cover_path')) {
                throw new RuntimeException('Simulated database failure');
            }
        });
        Volt::test('client.projects.editor', ['project' => $project])->set('cover', $this->image(220))
            ->set('form.title', 'Unsaved title')->call('save')->assertHasErrors('cover')->assertSet('form.title', 'Unsaved title');
        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertSame([$project->cover_path], Storage::disk('project_covers')->allFiles());
    }

    public function test_failed_initial_cover_save_leaves_no_project_or_file(): void
    {
        $this->client();
        Project::saving(function (Project $saving): void {
            if ($saving->isDirty('cover_path')) {
                throw new RuntimeException('Simulated initial cover failure');
            }
        });
        $this->form()->set('cover', $this->image())->call('save')->assertHasErrors('cover');
        $this->assertDatabaseCount('projects', 0);
        $this->assertSame([], Storage::disk('project_covers')->allFiles());
    }

    public function test_a_vetoed_project_creation_writes_no_cover_file(): void
    {
        $this->client();
        Project::creating(fn () => false);
        $this->form()->set('cover', $this->image())->call('save')->assertHasErrors('cover');
        $this->assertDatabaseCount('projects', 0);
        $this->assertSame([], Storage::disk('project_covers')->allFiles());
    }

    public function test_a_vetoed_replacement_preserves_the_previous_cover_and_removes_the_new_file(): void
    {
        $this->client();
        $project = $this->covered();
        Project::saving(fn () => false);
        Volt::test('client.projects.editor', ['project' => $project])->set('cover', $this->image(220))->call('save')->assertHasErrors('cover');
        $this->assertSame($project->cover_path, $project->fresh()->cover_path);
        $this->assertSame([$project->cover_path], Storage::disk('project_covers')->allFiles());
    }

    public function test_failed_removal_preserves_the_current_cover(): void
    {
        $this->client();
        $project = $this->covered();
        Project::saving(function (): void {
            throw new RuntimeException('Simulated failed removal');
        });
        Volt::test('client.projects.editor', ['project' => $project])->set('removeCover', true)
            ->call('save')->assertHasErrors('cover')->assertSet('removeCover', true);
        $this->assertSame($project->cover_path, $project->fresh()->cover_path);
        Storage::disk('project_covers')->assertExists($project->cover_path);
    }

    public function test_partial_storage_failure_is_cleaned_up_without_changing_the_previous_cover(): void
    {
        $this->client();
        $project = $this->covered();
        $disk = Storage::disk('project_covers');
        $failed = Mockery::mock(FilesystemAdapter::class);
        $failed->shouldReceive('exists')->with($project->cover_path)->andReturn(true);
        $failed->shouldReceive('get')->with($project->cover_path)->andReturn($disk->get($project->cover_path));
        $failed->shouldReceive('put')->once()->andReturnUsing(function ($path) use ($disk): void {
            $disk->put($path, 'partial write');
            throw new RuntimeException('Simulated storage failure');
        });
        $failed->shouldReceive('delete')->andReturnUsing(fn ($path) => $disk->delete($path));
        Storage::shouldReceive('disk')->with('project_covers')->andReturn($failed);
        try {
            app(SaveProjectDraft::class)->save($project->id, $this->details($project), $this->image(220));
            $this->fail('Storage failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated storage failure', $exception->getMessage());
        }
        $this->assertSame($project->cover_path, $project->fresh()->cover_path);
        $this->assertSame([$project->cover_path], $disk->allFiles());
    }

    public function test_outer_transaction_rollback_preserves_previous_cover_and_removes_new_file(): void
    {
        $this->client();
        $project = $this->covered();
        $new = null;
        try {
            DB::transaction(function () use ($project, &$new): void {
                $new = app(SaveProjectDraft::class)->save($project->id, $this->details($project), $this->image(220))->cover_path;
                Storage::disk('project_covers')->assertExists($project->cover_path);
                throw new RuntimeException('Abort enclosing transaction');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Abort enclosing transaction', $exception->getMessage());
        }
        $this->assertSame($project->cover_path, $project->fresh()->cover_path);
        Storage::disk('project_covers')->assertExists($project->cover_path);
        Storage::disk('project_covers')->assertMissing($new);
    }

    public function test_rolling_back_creation_then_deletion_in_one_outer_transaction_leaves_no_cover_file(): void
    {
        $this->client();
        try {
            DB::transaction(function (): void {
                $project = $this->covered();
                $project->delete();
                throw new RuntimeException('Undo creation and deletion');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Undo creation and deletion', $exception->getMessage());
        }
        $this->assertDatabaseCount('projects', 0);
        $this->assertSame([], Storage::disk('project_covers')->allFiles());
    }

    public function test_project_deletion_and_rollback_cleanup_respect_history_and_other_files(): void
    {
        $this->client();
        $project = $this->covered();
        $other = $this->covered();
        try {
            DB::transaction(function () use ($project): void {
                $project->delete();
                throw new RuntimeException('Undo deletion');
            });
        } catch (RuntimeException) {
            Storage::disk('project_covers')->assertExists($project->cover_path);
        }
        Project::query()->findOrFail($project->id)->delete();
        Storage::disk('project_covers')->assertMissing($project->cover_path);
        Storage::disk('project_covers')->assertExists($other->cover_path);
        Proposal::factory()->create(['project_id' => $other->id]);
        try {
            $other->delete();
            $this->fail('Proposal history must restrict deletion.');
        } catch (QueryException) {
            Storage::disk('project_covers')->assertExists($other->cover_path);
        }
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors('password');
        $this->assertModelExists(Auth::user());
        Storage::disk('project_covers')->assertExists($other->cover_path);
    }

    public function test_cleanup_never_deletes_a_current_cover_or_a_different_projects_file(): void
    {
        $this->client();
        $project = $this->covered();
        $other = $this->covered();
        $covers = app(ProjectCovers::class);
        $covers->deleteOwnedFile($project, $project->cover_path);
        $covers->deleteOwnedFile($other, $project->cover_path);
        $this->assertCount(2, Storage::disk('project_covers')->allFiles());
    }

    private function client(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($user);

        return $user;
    }

    private function covered(): Project
    {
        return app(SaveProjectDraft::class)->save(null, $this->details(), $this->image());
    }

    private function details(?Project $project = null): array
    {
        return ['title' => 'A real project brief', 'description' => 'Plain text project details.',
            'category_id' => $project?->category_id ?? Category::factory()->create()->id,
            'budget' => '25000.01', 'deadline' => '2026-11-01'];
    }

    private function form()
    {
        $component = Volt::test('client.projects.editor');
        foreach ($this->details() as $key => $value) {
            $component->set('form.'.$key, (string) $value);
        }

        return $component;
    }

    private function image(int $red = 20): UploadedFile
    {
        $image = imagecreatetruecolor(180, 90);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, 120, 140));
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('cover.png', $contents);
    }

    public static function otherRoles(): array
    {
        return [[User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }
}
