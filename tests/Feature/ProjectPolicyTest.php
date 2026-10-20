<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectPolicyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('roles')]
    public function test_only_clients_can_list_create_or_edit_their_own_drafts(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $project = Project::factory()->create(['client_id' => $user->id]);
        $gate = Gate::forUser($user);

        $this->assertSame($role === User::ROLE_CLIENT, $gate->allows('viewAny', Project::class));
        $this->assertSame($role === User::ROLE_CLIENT, $gate->allows('create', Project::class));
        $this->assertSame($role === User::ROLE_CLIENT, $gate->allows('update', $project));
        $this->assertSame($role === User::ROLE_CLIENT, $gate->allows('view', $project));
        $this->assertSame($role === User::ROLE_CLIENT, $gate->allows('publish', $project));
    }

    #[DataProvider('statuses')]
    public function test_editing_requires_both_ownership_and_draft_status(ProjectStatus $status): void
    {
        $project = Project::factory()->create(['status' => $status]);
        $other = User::factory()->create(['role' => User::ROLE_CLIENT]);

        $this->assertSame($status === ProjectStatus::Draft, Gate::forUser($project->client)->allows('update', $project));
        $this->assertFalse(Gate::forUser($other)->allows('update', $project));
        $this->assertTrue(Gate::forUser($project->client)->allows('view', $project));
        $this->assertFalse(Gate::forUser($other)->allows('view', $project));
        $this->assertSame($status === ProjectStatus::Draft, Gate::forUser($project->client)->allows('publish', $project));
        $this->assertFalse(Gate::forUser($other)->allows('publish', $project));
    }

    public function test_guests_have_no_project_permissions(): void
    {
        $project = Project::factory()->create();

        $this->assertFalse(Gate::allows('viewAny', Project::class));
        $this->assertFalse(Gate::allows('create', Project::class));
        $this->assertFalse(Gate::allows('update', $project));
        $this->assertFalse(Gate::allows('view', $project));
        $this->assertFalse(Gate::allows('publish', $project));
    }

    public static function roles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'freelancer' => [User::ROLE_FREELANCER], 'admin' => [User::ROLE_ADMIN]];
    }

    public static function statuses(): array
    {
        return array_map(fn (ProjectStatus $status) => [$status], ProjectStatus::cases());
    }
}
