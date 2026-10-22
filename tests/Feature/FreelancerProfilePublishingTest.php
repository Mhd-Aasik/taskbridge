<?php

namespace Tests\Feature;

use App\Actions\Profiles\ChangeProfileVisibility;
use App\Models\Skill;
use App\Models\User;
use App\Support\FreelancerProfiles;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsFreelancerDiscovery;
use Tests\TestCase;

class FreelancerProfilePublishingTest extends TestCase
{
    use BuildsFreelancerDiscovery, RefreshDatabase;

    public function test_visibility_metadata_defaults_and_mass_assignment_are_protected(): void
    {
        $profile = $this->professionalProfile();
        $this->assertFalse($profile->is_public);
        $this->assertFalse($profile->share_account_photo);
        $this->assertNull($profile->first_published_at);
        $this->assertSame(0, $profile->profile_version);
        foreach (['user_id', 'is_public', 'share_account_photo', 'first_published_at', 'profile_version'] as $field) {
            $this->assertFalse($profile->isFillable($field));
        }
        $before = $profile->getAttributes();
        $profile->fill(['user_id' => 999, 'is_public' => true, 'share_account_photo' => true, 'first_published_at' => now(), 'profile_version' => 999])->save();
        $this->assertSame($before, $profile->fresh()->getAttributes());
    }

    public function test_preview_is_owner_only_and_gets_do_not_create_or_publish(): void
    {
        $user = User::factory()->create(['role' => 'freelancer']);
        $this->get(route('freelancer.profile.preview'))->assertRedirect(route('login'));
        $this->actingAs($user)->get(route('freelancer.profile.preview'))->assertOk()->assertSeeText('Save your profile first.');
        $this->assertDatabaseCount('freelancer_profiles', 0);
        $own = $this->professionalProfile(['user_id' => $user->id, 'bio' => "My saved biography.\nA second line."]);
        $other = $this->professionalProfile(['headline' => 'OTHER-PRIVATE-HEADLINE']);
        $before = $own->getAttributes();
        $this->get(route('freelancer.profile.preview', ['profile' => $other->id]))->assertOk()->assertSeeText('My saved biography.')->assertDontSee('OTHER-PRIVATE-HEADLINE');
        $this->assertSame($before, $own->fresh()->getAttributes());
        foreach (['client', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('freelancer.profile.preview'))->assertForbidden();
        }
    }

    public function test_explicit_publish_retry_unpublish_and_republish_preserve_first_utc_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00', 'Asia/Colombo'));
        $profile = $this->professionalProfile();
        $this->actingAs($profile->user);
        $component = Volt::test('freelancer.profile');
        $component->call('publish')->assertHasNoErrors()->assertSeeText('Visible to Clients');
        $first = $profile->fresh()->first_published_at;
        $updated = $profile->fresh()->getRawOriginal('updated_at');
        $this->assertSame('2026-11-05 04:30:00', $first->utc()->format('Y-m-d H:i:s'));
        $this->travel(2)->hours();
        $component->call('publish')->assertHasNoErrors();
        $this->assertSame($updated, $profile->fresh()->getRawOriginal('updated_at'));
        $component->call('unpublish')->assertHasNoErrors()->assertSeeText('Profile visibility: Private.');
        $this->assertFalse($profile->fresh()->is_public);
        $component->call('publish')->assertHasNoErrors();
        $this->assertTrue($profile->fresh()->is_public);
        $this->assertTrue($first->equalTo($profile->fresh()->first_published_at));
    }

    public function test_publishing_uses_saved_details_and_cannot_target_a_forged_owner(): void
    {
        $own = $this->professionalProfile();
        $other = $this->professionalProfile();
        $this->actingAs($own->user);
        Volt::test('freelancer.profile')->set('headline', 'UNSAVED-HEADLINE')
            ->call('publish', ['user_id' => $other->user_id, 'profile_id' => $other->id, 'first_published_at' => '1999-01-01', 'role' => 'admin', 'share_account_photo' => true])->assertHasNoErrors();
        $this->assertTrue($own->fresh()->is_public);
        $this->assertSame('Laravel specialist', $own->fresh()->headline);
        $this->assertFalse($other->fresh()->is_public);
        $this->assertFalse($own->fresh()->share_account_photo);
        $this->assertSame('freelancer', $own->user->fresh()->role);
    }

    public function test_ordinary_profile_save_cannot_publish_or_save_photo_consent(): void
    {
        $profile = $this->professionalProfile();
        $this->actingAs($profile->user);
        Volt::test('freelancer.profile')->set('shareAccountPhoto', true)->set('headline', 'Saved update')
            ->call('save', ['is_public' => true, 'first_published_at' => now(), 'share_account_photo' => true])->assertHasNoErrors();
        $this->assertFalse($profile->fresh()->is_public);
        $this->assertFalse($profile->fresh()->share_account_photo);
        $this->assertNull($profile->fresh()->first_published_at);
    }

    public function test_photo_consent_requires_an_explicit_separate_save_and_can_be_revoked(): void
    {
        $profile = $this->professionalProfile();
        $this->actingAs($profile->user);
        $component = Volt::test('freelancer.profile')->set('shareAccountPhoto', true);
        $this->assertFalse($profile->fresh()->share_account_photo);
        $component->call('savePhotoPreference')->assertHasNoErrors();
        $this->assertTrue($profile->fresh()->share_account_photo);
        $this->assertFalse($profile->fresh()->is_public);
        $component->set('shareAccountPhoto', false)->call('savePhotoPreference')->assertHasNoErrors();
        $this->assertFalse($profile->fresh()->share_account_photo);
    }

    public function test_validation_failure_retains_input_and_corrected_save_clears_old_errors(): void
    {
        $profile = $this->professionalProfile();
        $this->actingAs($profile->user);
        $component = Volt::test('freelancer.profile')->set('headline', '')->set('bio', 'Retained biography')
            ->call('save')->assertHasErrors(['headline'])->assertSet('bio', 'Retained biography');
        $this->assertSame('Laravel specialist', $profile->fresh()->headline);
        $component->set('headline', 'Corrected headline')->call('save')->assertHasNoErrors()->call('publish')->assertHasNoErrors();
        $this->assertTrue($profile->fresh()->is_public);
        $this->assertSame('Retained biography', $profile->fresh()->bio);
    }

    #[DataProvider('invalidSavedDetails')]
    public function test_invalid_saved_profiles_cannot_be_published(string $field, mixed $value): void
    {
        $profile = $this->professionalProfile();
        if ($field === 'skill_ids') {
            $profile->skills()->sync($value === 'eleven' ? Skill::factory()->count(11)->create()->modelKeys() : []);
        } else {
            DB::table('freelancer_profiles')->where('id', $profile->id)->update([$field => $value]);
        }
        $profile->refresh();
        $before = $profile->getAttributes();
        $this->actingAs($profile->user);
        Volt::test('freelancer.profile')->call('publish')->assertHasErrors([$field]);
        $this->assertSame($before, $profile->fresh()->getAttributes());
    }

    public function test_stale_profile_snapshot_is_refused_and_does_not_publish_changed_content(): void
    {
        $profile = $this->professionalProfile();
        $this->actingAs($profile->user);
        $component = Volt::test('freelancer.profile');
        $profile->update(['headline' => 'Changed in another session']);
        $component->call('publish')->assertHasErrors(['visibility']);
        $this->assertFalse($profile->fresh()->is_public);
        $this->assertNull($profile->fresh()->first_published_at);
    }

    public function test_stale_editor_cannot_expose_edits_after_visibility_changed_elsewhere(): void
    {
        $profile = $this->professionalProfile();
        $this->actingAs($profile->user);
        $component = Volt::test('freelancer.profile')->set('bio', 'Unsaved private edit');
        app(ChangeProfileVisibility::class)->publish(FreelancerProfiles::revision($profile));
        $component->call('save')->assertHasErrors(['visibility'])->assertSet('bio', 'Unsaved private edit');
        $this->assertSame($profile->bio, $profile->fresh()->bio);
        $this->assertTrue($profile->fresh()->is_public);
    }

    public function test_same_second_visibility_cycles_do_not_revalidate_an_old_snapshot(): void
    {
        $this->freezeTime();
        $profile = $this->visibleProfile();
        $this->actingAs($profile->user);
        $action = app(ChangeProfileVisibility::class);
        $old = FreelancerProfiles::revision($profile);
        $action->unpublish($old);
        $action->publish(FreelancerProfiles::revision($profile->fresh()));
        $this->assertSame(2, $profile->fresh()->profile_version);
        $this->expectException(ValidationException::class);
        $action->unpublish($old);
    }

    #[DataProvider('changedAccounts')]
    public function test_persisted_role_and_active_state_are_rechecked_for_every_mutation(array $change): void
    {
        $profile = $this->professionalProfile();
        $this->actingAs($profile->user);
        $revision = FreelancerProfiles::revision($profile);
        User::query()->whereKey($profile->user_id)->update($change);
        foreach (['publish', 'unpublish', 'consent'] as $operation) {
            try {
                $action = app(ChangeProfileVisibility::class);
                $operation === 'consent' ? $action->photoConsent(true, $revision) : $action->$operation($revision);
                $this->fail('Changed accounts cannot mutate profile visibility.');
            } catch (AuthorizationException|HttpException $exception) {
                if ($exception instanceof HttpException) {
                    $this->assertSame(403, $exception->getStatusCode());
                } else {
                    $this->assertInstanceOf(AuthorizationException::class, $exception);
                }
            }
        }
        $this->assertFalse($profile->fresh()->is_public);
        $this->assertFalse($profile->fresh()->share_account_photo);
    }

    public function test_missing_saved_profile_gives_an_actionable_error_without_creating_records(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'freelancer']));
        Volt::test('freelancer.profile')->call('publish')->assertHasErrors(['visibility'])->call('savePhotoPreference')->assertHasErrors(['visibility']);
        $this->assertDatabaseCount('freelancer_profiles', 0);
    }

    public function test_visibility_and_first_publication_time_roll_back_together(): void
    {
        $profile = $this->professionalProfile();
        $this->actingAs($profile->user);
        $before = $profile->getAttributes();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, 'freelancer_profiles')) {
                throw new RuntimeException('Injected publication failure');
            }
        });
        try {
            app(ChangeProfileVisibility::class)->publish(FreelancerProfiles::revision($profile));
            $this->fail('The injected publication failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected publication failure', $exception->getMessage());
        }
        $this->assertSame($before, $profile->fresh()->getAttributes());
    }

    public static function changedAccounts(): array
    {
        return ['Client' => [['role' => 'client']], 'Admin' => [['role' => 'admin']], 'suspended' => [['suspended_at' => '2026-11-01 00:00:00', 'access_version' => 1]]];
    }

    public static function invalidSavedDetails(): array
    {
        return [
            'missing headline' => ['headline', null], 'blank headline' => ['headline', '   '], 'long headline' => ['headline', str_repeat('a', 121)],
            'missing biography' => ['bio', null], 'blank biography' => ['bio', " \n\t"], 'long biography' => ['bio', str_repeat('b', 2001)],
            'long location' => ['location', str_repeat('l', 121)], 'unsafe URL' => ['portfolio_url', 'javascript:alert(1)'],
            'negative rate' => ['hourly_rate', '-0.01'], 'missing skills' => ['skill_ids', []], 'too many skills' => ['skill_ids', 'eleven'],
        ];
    }
}
