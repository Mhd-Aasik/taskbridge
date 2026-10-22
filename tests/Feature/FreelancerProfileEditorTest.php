<?php

namespace Tests\Feature;

use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class FreelancerProfileEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('freelancer.profile.edit'))->assertRedirect(route('login'));
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_open_the_editor_or_mount_the_component(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('freelancer.profile.edit'))->assertForbidden();
        Volt::test('freelancer.profile')->assertForbidden();
    }

    public function test_a_freelancer_can_open_an_empty_editor_without_creating_a_profile(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $skill = Skill::factory()->create(['name' => 'Laravel']);

        $this->actingAs($user)->get(route('freelancer.profile.edit'))
            ->assertOk()->assertSeeText('Freelancer profile')->assertSeeText('Laravel')
            ->assertSeeText('Profile visibility: Private.');

        Volt::test('freelancer.profile')->assertSet('headline', '')->assertSet('skill_ids', [])->assertSet('saved', false);
        $this->assertDatabaseCount('freelancer_profiles', 0);
    }

    public function test_a_freelancer_can_create_a_private_profile_and_assign_skills(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $skills = Skill::factory()->count(2)->create();

        $this->actingAs($user);
        Volt::test('freelancer.profile')->set($this->details($skills->modelKeys()))
            ->call('save')->assertHasNoErrors()->assertSet('saved', true)
            ->assertSee('Freelancer profile saved.')->assertSee('role="status"', false);

        $profile = $user->fresh()->freelancerProfile;
        $this->assertSame('Laravel developer', $profile->headline);
        $this->assertSame('I build thoughtful, accessible applications.', $profile->bio);
        $this->assertSame('Colombo', $profile->location);
        $this->assertSame('https://example.com/work', $profile->portfolio_url);
        $this->assertSame('2500.50', $profile->hourly_rate);
        $this->assertFalse($profile->is_public);
        $this->assertSame(User::ROLE_FREELANCER, $user->fresh()->role);
        $this->assertEqualsCanonicalizing($skills->modelKeys(), $profile->skills->modelKeys());
    }

    public function test_existing_profile_fields_and_skills_are_loaded(): void
    {
        $profile = FreelancerProfile::factory()->create(['is_public' => true]);
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);

        $this->actingAs($profile->user);
        Volt::test('freelancer.profile')->assertSet('headline', $profile->headline)
            ->assertSet('bio', $profile->bio)->assertSet('location', $profile->location)
            ->assertSet('portfolio_url', $profile->portfolio_url)->assertSet('hourly_rate', $profile->hourly_rate)
            ->assertSet('skill_ids', [$skill->id])->assertSeeText('Profile visibility: Visible to Clients.');
    }

    #[DataProvider('visibilityStates')]
    public function test_updates_sync_skills_without_changing_visibility_or_role(bool $isPublic): void
    {
        $profile = FreelancerProfile::factory()->create(['is_public' => $isPublic]);
        $oldSkill = Skill::factory()->create();
        $newSkill = Skill::factory()->create();
        $profile->skills()->attach($oldSkill);

        $this->actingAs($profile->user);
        Volt::test('freelancer.profile')->set($this->details([$newSkill->id]))
            ->call('save')->assertHasNoErrors()->assertSet('saved', true);

        $this->assertSame('Laravel developer', $profile->fresh()->headline);
        $this->assertSame($isPublic, $profile->fresh()->is_public);
        $this->assertSame(User::ROLE_FREELANCER, $profile->user->fresh()->role);
        $this->assertSame([$newSkill->id], $profile->fresh()->skills->modelKeys());
        $this->assertDatabaseCount('freelancer_profiles', 1);
        $this->assertModelExists($oldSkill);
    }

    #[DataProvider('transactionModes')]
    public function test_submitted_action_parameters_cannot_target_another_user_or_change_visibility_or_role(bool $existing): void
    {
        $target = FreelancerProfile::factory()->create(['headline' => 'Other freelancer', 'is_public' => true]);
        $attacker = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $ownProfile = $existing ? FreelancerProfile::factory()->create(['user_id' => $attacker->id]) : null;
        $skill = Skill::factory()->create();

        $this->actingAs($attacker);
        Volt::test('freelancer.profile')->set($this->details([$skill->id]))
            ->call('save', ['user_id' => $target->user_id, 'profile_id' => $target->id, 'role' => User::ROLE_ADMIN, 'is_public' => true])
            ->assertHasNoErrors();

        $this->assertSame('Other freelancer', $target->fresh()->headline);
        $this->assertSame($target->user_id, $target->fresh()->user_id);
        $this->assertTrue($target->fresh()->is_public);
        $this->assertFalse($attacker->fresh()->freelancerProfile->is_public);
        $this->assertSame(User::ROLE_FREELANCER, $attacker->fresh()->role);
        $this->assertSame($attacker->id, $attacker->fresh()->freelancerProfile->user_id);
        if ($ownProfile) {
            $this->assertSame($ownProfile->id, $attacker->fresh()->freelancerProfile->id);
        }
    }

    public function test_query_parameters_cannot_load_another_freelancers_profile(): void
    {
        $own = FreelancerProfile::factory()->create(['headline' => 'My own headline']);
        $other = FreelancerProfile::factory()->create(['headline' => 'Another private headline']);

        $this->actingAs($own->user)->get(route('freelancer.profile.edit', ['profile_id' => $other->id, 'user_id' => $other->user_id]))
            ->assertOk()->assertSee('My own headline')->assertDontSee('Another private headline');
    }

    #[DataProvider('protectedProperties')]
    public function test_sensitive_properties_are_not_client_editable(string $property, mixed $value): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $this->actingAs($user);
        $component = Volt::test('freelancer.profile');

        $this->expectException(PublicPropertyNotFoundException::class);
        $component->set($property, $value);
    }

    #[DataProvider('otherRoles')]
    public function test_a_role_change_after_loading_prevents_saving(string $role): void
    {
        $profile = FreelancerProfile::factory()->create(['headline' => 'Original']);
        $skill = Skill::factory()->create();
        $this->actingAs($profile->user);
        $component = Volt::test('freelancer.profile')->set($this->details([$skill->id]));
        User::query()->whereKey($profile->user_id)->update(['role' => $role]);

        $component->call('save')->assertForbidden();

        $this->assertSame('Original', $profile->fresh()->headline);
        $this->assertDatabaseCount('freelancer_profile_skill', 0);
    }

    public function test_a_logged_out_user_cannot_save_an_already_loaded_editor(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $skill = Skill::factory()->create();
        $this->actingAs($user);
        $component = Volt::test('freelancer.profile')->set($this->details([$skill->id]));
        Auth::logout();

        $component->call('save')->assertForbidden();
        $this->assertDatabaseCount('freelancer_profiles', 0);
    }

    public function test_policy_authorisation_is_checked_on_page_access(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        Gate::before(fn () => false);

        $this->actingAs($user)->get(route('freelancer.profile.edit'))->assertForbidden();
    }

    public function test_policy_authorisation_is_checked_on_every_save(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $skill = Skill::factory()->create();
        $this->actingAs($user);
        $component = Volt::test('freelancer.profile')->set($this->details([$skill->id]));
        Gate::before(fn () => false);

        $component->call('save')->assertForbidden();
        $this->assertDatabaseCount('freelancer_profiles', 0);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_keeps_entered_values_and_does_not_change_stored_data(string $field, mixed $value, string $error): void
    {
        $profile = FreelancerProfile::factory()->create();
        $skills = Skill::factory()->count(11)->create();
        $profile->skills()->attach($skills->first());
        $before = $profile->fresh()->getAttributes();

        $value = match ($value) {
            '__duplicates__' => [$skills[0]->id, $skills[0]->id],
            '__mixed_duplicates__' => [$skills[0]->id, (string) $skills[0]->id],
            '__eleven__' => $skills->modelKeys(),
            default => $value,
        };

        $this->actingAs($profile->user);
        Volt::test('freelancer.profile')->set($this->details([$skills[1]->id]))
            ->set($field, $value)->call('save')->assertHasErrors([$error])->assertSet($field, $value)->assertSet('saved', false);

        $this->assertSame($before, $profile->fresh()->getAttributes());
        $this->assertSame([$skills->first()->id], $profile->fresh()->skills->modelKeys());
        $this->assertSame(User::ROLE_FREELANCER, $profile->user->fresh()->role);
        $this->assertDatabaseCount('freelancer_profiles', 1);
    }

    public function test_valid_maximum_lengths_rate_and_ten_skills_can_be_saved(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $skills = Skill::factory()->count(10)->create();
        $url = 'https://example.com/';
        $url .= str_repeat('a', 2048 - strlen($url));

        $this->actingAs($user);
        Volt::test('freelancer.profile')->set([
            'headline' => str_repeat('h', 120), 'bio' => str_repeat('b', 2000),
            'location' => str_repeat('l', 120), 'portfolio_url' => $url,
            'hourly_rate' => '9999999999.99', 'skill_ids' => $skills->modelKeys(),
        ])->call('save')->assertHasNoErrors();

        $this->assertSame('9999999999.99', $user->fresh()->freelancerProfile->hourly_rate);
        $this->assertSame($url, $user->fresh()->freelancerProfile->portfolio_url);
        $this->assertCount(10, $user->fresh()->freelancerProfile->skills);
    }

    #[DataProvider('validRates')]
    public function test_optional_fields_and_valid_rate_values_are_saved_correctly(string $rate, ?string $expected): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $skill = Skill::factory()->create();
        $this->actingAs($user);

        Volt::test('freelancer.profile')->set($this->details([$skill->id]))
            ->set('hourly_rate', $rate)->set('location', '')->set('portfolio_url', '')
            ->call('save')->assertHasNoErrors();

        $profile = $user->fresh()->freelancerProfile;
        $this->assertSame($expected, $profile->hourly_rate);
        $this->assertNull($profile->location);
        $this->assertNull($profile->portfolio_url);
    }

    public function test_http_portfolio_addresses_are_accepted(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $skill = Skill::factory()->create();
        $this->actingAs($user);

        Volt::test('freelancer.profile')->set($this->details([$skill->id]))
            ->set('portfolio_url', 'http://example.com/work')->call('save')->assertHasNoErrors();

        $this->assertSame('http://example.com/work', $user->fresh()->freelancerProfile->portfolio_url);
    }

    public function test_biography_is_rendered_as_escaped_plain_text(): void
    {
        $bio = '<script>alert("xss")</script><b>My work</b>';
        $profile = FreelancerProfile::factory()->create(['bio' => $bio]);
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);
        $this->actingAs($profile->user);

        Volt::test('freelancer.profile')->call('save')->assertHasNoErrors();
        $this->get(route('freelancer.profile.edit'))->assertOk()
            ->assertSee(e($bio), false)->assertDontSee($bio, false);
        $this->assertSame($bio, $profile->fresh()->bio);
    }

    #[DataProvider('transactionModes')]
    public function test_profile_and_skills_roll_back_together_if_pivot_insertion_fails(bool $existing): void
    {
        $this->withoutExceptionHandling();
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $oldSkill = Skill::factory()->create();
        $newSkill = Skill::factory()->create();
        $profile = $existing ? FreelancerProfile::factory()->create(['user_id' => $user->id, 'headline' => 'Original']) : null;
        $profile?->skills()->attach($oldSkill);
        $this->actingAs($user);
        $component = Volt::test('freelancer.profile')->set($this->details([$newSkill->id]));

        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'freelancer_profile_skill')) {
                throw new RuntimeException('Simulated skill assignment failure');
            }
        });

        try {
            $component->call('save');
            $this->fail('The simulated pivot failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated skill assignment failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('freelancer_profiles', $existing ? 1 : 0);
        $this->assertDatabaseCount('freelancer_profile_skill', $existing ? 1 : 0);
        if ($profile) {
            $this->assertSame('Original', $profile->fresh()->headline);
            $this->assertSame([$oldSkill->id], $profile->fresh()->skills->modelKeys());
        }
    }

    private function details(array $skillIds): array
    {
        return [
            'headline' => 'Laravel developer', 'bio' => 'I build thoughtful, accessible applications.',
            'location' => 'Colombo', 'portfolio_url' => 'https://example.com/work',
            'hourly_rate' => '2500.50', 'skill_ids' => $skillIds,
        ];
    }

    public static function otherRoles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'admin' => [User::ROLE_ADMIN]];
    }

    public static function visibilityStates(): array
    {
        return ['private' => [false], 'public' => [true]];
    }

    public static function transactionModes(): array
    {
        return ['create' => [false], 'update' => [true]];
    }

    public static function protectedProperties(): array
    {
        return ['owner' => ['user_id', 999], 'profile' => ['profile_id', 999], 'role' => ['role', 'admin'], 'visibility' => ['is_public', true]];
    }

    public static function validRates(): array
    {
        return ['empty' => ['', null], 'zero' => ['0', '0.00'], 'fraction' => ['0.01', '0.01'], 'one decimal' => ['1250.5', '1250.50']];
    }

    public static function invalidInputs(): array
    {
        return [
            'headline required' => ['headline', '', 'headline'],
            'headline whitespace' => ['headline', '   ', 'headline'],
            'headline length' => ['headline', str_repeat('h', 121), 'headline'],
            'bio required' => ['bio', '', 'bio'],
            'bio whitespace' => ['bio', '   ', 'bio'],
            'bio length' => ['bio', str_repeat('b', 2001), 'bio'],
            'location length' => ['location', str_repeat('l', 121), 'location'],
            'URL length' => ['portfolio_url', 'https://example.com/'.str_repeat('a', 2048), 'portfolio_url'],
            'FTP URL' => ['portfolio_url', 'ftp://example.com/work', 'portfolio_url'],
            'file URL' => ['portfolio_url', 'file:///etc/passwd', 'portfolio_url'],
            'javascript URL' => ['portfolio_url', 'javascript:alert(1)', 'portfolio_url'],
            'data URL' => ['portfolio_url', 'data:text/html,hello', 'portfolio_url'],
            'relative URL' => ['portfolio_url', '/work', 'portfolio_url'],
            'invalid URL' => ['portfolio_url', 'not a URL', 'portfolio_url'],
            'negative rate' => ['hourly_rate', '-0.01', 'hourly_rate'],
            'rate too large' => ['hourly_rate', '10000000000', 'hourly_rate'],
            'three decimal places' => ['hourly_rate', '1.001', 'hourly_rate'],
            'excess trailing decimals' => ['hourly_rate', '1.230', 'hourly_rate'],
            'scientific notation' => ['hourly_rate', '1e3', 'hourly_rate'],
            'non numeric rate' => ['hourly_rate', 'abc', 'hourly_rate'],
            'skills required' => ['skill_ids', [], 'skill_ids'],
            'too many skills' => ['skill_ids', '__eleven__', 'skill_ids'],
            'duplicate skills' => ['skill_ids', '__duplicates__', 'skill_ids.0'],
            'mixed duplicate skills' => ['skill_ids', '__mixed_duplicates__', 'skill_ids.0'],
            'unknown skill' => ['skill_ids', [999999], 'skill_ids.0'],
            'invalid skill id' => ['skill_ids', ['abc'], 'skill_ids.0'],
            'fractional skill id' => ['skill_ids', ['1.5'], 'skill_ids.0'],
            'null skill id' => ['skill_ids', [null], 'skill_ids.0'],
        ];
    }
}
