<?php

namespace Tests\Feature\Settings;

use App\Models\DisputeCase;
use App\Models\DisputeResolution;
use App\Models\DisputeStatement;
use App\Models\FreelancerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DisputeAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('histories')]
    public function test_history_guard_refuses_deletion_before_profile_or_avatar_cleanup(string $kind, string $role): void
    {
        Storage::fake('avatars');
        $actor = User::factory()->create(['role' => $role, 'avatar_path' => 'avatars/history-owner/photo.png']);
        Storage::disk('avatars')->put($actor->avatar_path, 'Private original photo');
        $profile = FreelancerProfile::factory()->create(['user_id' => $actor->id]);
        $history = match ($kind) {
            'opening' => DisputeCase::factory()->create(['opened_by' => $actor->id]),
            'statement' => DisputeStatement::factory()->create(['submitted_by' => $actor->id]),
            'resolution' => DisputeResolution::factory()->create(['decided_by' => $actor->id]),
        };
        $before = $history->fresh()->getAttributes();
        $this->actingAs($actor);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])->assertSeeText('Your account cannot be deleted because it has dispute history.')->assertNoRedirect();
        $this->assertAuthenticatedAs($actor);
        $this->assertModelExists($actor);
        $this->assertModelExists($profile);
        $this->assertSame('Private original photo', Storage::disk('avatars')->get($actor->avatar_path));
        $this->assertSame($before, $history->fresh()->getAttributes());
    }

    #[DataProvider('roles')]
    public function test_unrelated_dispute_history_does_not_block_accounts_without_history(string $role): void
    {
        Storage::fake('avatars');
        $case = DisputeCase::factory()->create();
        if ($role === User::ROLE_ADMIN) {
            User::factory()->create(['role' => User::ROLE_ADMIN]);
        }
        $actor = User::factory()->create(['role' => $role, 'avatar_path' => null]);
        $this->actingAs($actor);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');
        $this->assertModelMissing($actor);
        $this->assertModelExists($case);
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function histories(): array
    {
        $cases = [];
        foreach (['opening', 'statement', 'resolution'] as $kind) {
            foreach (self::roles() as [$role]) {
                $cases[] = [$kind, $role];
            }
        }

        return $cases;
    }
}
