<?php

namespace Tests\Feature;

use App\Models\ProjectAssignment;
use App\Models\Skill;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsFreelancerDiscovery;
use Tests\Concerns\BuildsProjectFeedback;
use Tests\TestCase;

class FreelancerDiscoveryTest extends TestCase
{
    use BuildsFreelancerDiscovery, BuildsProjectFeedback, RefreshDatabase;

    public function test_guests_and_other_roles_cannot_browse_or_view_profiles(): void
    {
        $profile = $this->visibleProfile();
        foreach (['client.freelancers.index', 'client.freelancers.show'] as $route) {
            $arguments = $route === 'client.freelancers.index' ? [] : [$profile->id];
            $this->get(route($route, $arguments))->assertRedirect(route('login'));
            foreach (['freelancer', 'admin'] as $role) {
                $this->actingAs(User::factory()->create(['role' => $role]))->get(route($route, $arguments))->assertForbidden();
            }
            Auth::logout();
        }
    }

    #[DataProvider('ineligibleTargets')]
    public function test_ineligible_targets_are_excluded_from_lists_and_direct_urls(string $case): void
    {
        $profile = $this->visibleProfile(['headline' => 'INELIGIBLE-TARGET-HEADLINE']);
        match ($case) {
            'private' => $profile->forceFill(['is_public' => false])->save(),
            'suspended' => $profile->user->forceFill(['suspended_at' => now()])->save(),
            'client' => $profile->user->forceFill(['role' => 'client'])->save(),
            'admin' => $profile->user->forceFill(['role' => 'admin'])->save(),
            'missing' => $profile->delete(),
        };
        $this->discoveryClient();
        $this->get(route('client.freelancers.index'))->assertOk()->assertDontSee('INELIGIBLE-TARGET-HEADLINE');
        $this->get(route('client.freelancers.show', $profile->id))->assertNotFound();
    }

    public function test_suspended_viewers_are_denied_even_with_a_previously_authenticated_user(): void
    {
        $profile = $this->visibleProfile();
        $viewer = $this->discoveryClient();
        User::query()->whereKey($viewer->id)->update(['suspended_at' => now(), 'access_version' => 1]);
        foreach (['client.freelancers.index', 'client.freelancers.show', 'client.freelancers.photo'] as $route) {
            $this->actingAs($viewer)->getJson(route($route, $route === 'client.freelancers.index' ? [] : [$profile->id]))->assertForbidden();
        }
    }

    public function test_search_is_literal_safe_across_display_name_headline_and_biography(): void
    {
        $profile = $this->visibleProfile(['headline' => '100%_! Laravel', 'bio' => 'Special biography word.']);
        $profile->user->update(['name' => 'Unique Display Name']);
        $other = $this->visibleProfile(['headline' => 'Other work', 'bio' => 'Unrelated text.']);
        $this->discoveryClient();
        foreach (['100%_!', 'Special biography', 'Unique Display'] as $term) {
            $this->get(route('client.freelancers.index', ['search' => $term]))->assertOk()
                ->assertViewHas('profiles', fn ($profiles) => $profiles->pluck('id')->all() === [$profile->id]);
        }
        $this->get(route('client.freelancers.index', ['search' => '%']))->assertOk()->assertDontSeeText($other->headline);
    }

    public function test_combined_skills_use_all_semantics_with_location_and_exact_rate_filters(): void
    {
        $skills = Skill::factory()->count(2)->create();
        $wanted = $this->visibleProfile(['hourly_rate' => '100.01', 'location' => 'Colombo 100%'], $skills->modelKeys());
        $this->visibleProfile(['hourly_rate' => '100.01', 'location' => 'Colombo 100%'], [$skills[0]->id]);
        $this->visibleProfile(['hourly_rate' => '100.02', 'location' => 'Colombo 100%'], $skills->modelKeys());
        $this->visibleProfile(['hourly_rate' => '100.01', 'location' => 'Colombo elsewhere'], $skills->modelKeys());
        $this->discoveryClient();
        $this->get(route('client.freelancers.index', ['skills' => $skills->modelKeys(), 'location' => '100%', 'min_rate' => '100.01', 'max_rate' => '100.01']))->assertOk()
            ->assertViewHas('profiles', fn ($profiles) => $profiles->pluck('id')->all() === [$wanted->id]);
    }

    public function test_null_and_zero_rates_and_maximum_decimal_amounts_remain_distinct(): void
    {
        $null = $this->visibleProfile(['hourly_rate' => null]);
        $zero = $this->visibleProfile(['hourly_rate' => '0.00']);
        $max = $this->visibleProfile(['hourly_rate' => '9999999999.99']);
        $this->discoveryClient();
        $this->get(route('client.freelancers.index'))->assertOk()->assertSeeText('Not specified')->assertSeeText('LKR 0.00')->assertSeeText('LKR 9,999,999,999.99')
            ->assertViewHas('profiles', fn ($profiles) => $profiles->total() === 3);
        $this->get(route('client.freelancers.index', ['min_rate' => '0', 'max_rate' => '0']))->assertOk()
            ->assertViewHas('profiles', fn ($profiles) => $profiles->pluck('id')->all() === [$zero->id]);
        $this->get(route('client.freelancers.index', ['min_rate' => '9999999999.99']))->assertOk()
            ->assertViewHas('profiles', fn ($profiles) => $profiles->pluck('id')->all() === [$max->id]);
    }

    #[DataProvider('sortOptions')]
    public function test_whitelisted_sorting_has_deterministic_ties_and_null_rates_last(string $sort, array $positions): void
    {
        $first = $this->visibleProfile(['hourly_rate' => '10.00', 'first_published_at' => '2026-10-01 12:00:00']);
        $second = $this->visibleProfile(['hourly_rate' => '20.00', 'first_published_at' => '2026-10-02 12:00:00']);
        $third = $this->visibleProfile(['hourly_rate' => null, 'first_published_at' => '2026-10-03 12:00:00']);
        $first->user->update(['name' => 'Ari']);
        $second->user->update(['name' => 'Bina']);
        $third->user->update(['name' => 'Bina']);
        $ids = [$first->id, $second->id, $third->id];
        $this->discoveryClient();
        $this->get(route('client.freelancers.index', ['sort' => $sort]))->assertOk()
            ->assertViewHas('profiles', fn ($profiles) => $profiles->pluck('id')->all() === array_map(fn ($index) => $ids[$index], $positions));
    }

    public function test_rating_sort_and_summaries_use_only_released_freelancer_reputation(): void
    {
        $released = $this->completedAssignment(CarbonImmutable::now('UTC')->subDays(15));
        $blind = $this->completedAssignment();
        $this->feedbackFrom($released, attributes: ['rating' => 3, 'feedback' => 'RELEASED-PRIVATE-FEEDBACK']);
        $this->feedbackFrom($blind, attributes: ['rating' => 5, 'feedback' => 'BLIND-PRIVATE-FEEDBACK']);
        $rated = $this->visibleProfile(['user_id' => $released->freelancer_id]);
        $unrated = $this->visibleProfile(['user_id' => $blind->freelancer_id]);
        $this->discoveryClient();
        $this->get(route('client.freelancers.index', ['sort' => 'rating']))->assertOk()
            ->assertViewHas('profiles', fn ($profiles) => $profiles->pluck('id')->all() === [$rated->id, $unrated->id])
            ->assertSeeText('3.0 / 5')->assertSeeText('Not rated yet')->assertDontSee('5.0 / 5')
            ->assertDontSee('BLIND-PRIVATE-FEEDBACK')->assertDontSee('RELEASED-PRIVATE-FEEDBACK')->assertDontSee($released->project->title);
        $this->get(route('client.freelancers.show', $unrated->id))->assertOk()->assertSeeText('Not rated yet')->assertDontSee('BLIND-PRIVATE-FEEDBACK');
    }

    public function test_pagination_preserves_filters_and_a_new_filter_submission_starts_at_page_one(): void
    {
        $skill = Skill::factory()->create();
        for ($index = 0; $index < 12; $index++) {
            $this->visibleProfile(['headline' => 'Matching professional'], [$skill->id]);
        }
        $this->discoveryClient();
        $filters = ['search' => 'Matching', 'skills' => [$skill->id], 'sort' => 'name'];
        $response = $this->get(route('client.freelancers.index', $filters))->assertOk()
            ->assertViewHas('profiles', fn ($profiles) => $profiles->count() === 10 && $profiles->total() === 12);
        $pageOne = $response->viewData('profiles')->pluck('id')->all();
        $response = $this->get(route('client.freelancers.index', [...$filters, 'page' => 2]))->assertOk()
            ->assertViewHas('profiles', fn ($profiles) => $profiles->count() === 2 && $profiles->currentPage() === 2);
        $this->assertSame([], array_intersect($pageOne, $response->viewData('profiles')->pluck('id')->all()));
        $this->assertStringContainsString('search=Matching', $response->viewData('profiles')->url(1));
        $response->assertDontSee('name="page"', false);
        $this->get(route('client.freelancers.index', $filters))->assertViewHas('profiles', fn ($profiles) => $profiles->currentPage() === 1);
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_parameters_are_bounded_and_show_accessible_errors(array $filters): void
    {
        $this->visibleProfile(['headline' => 'DO-NOT-RETURN-ON-INVALID-FILTER']);
        $this->discoveryClient();
        $this->get(route('client.freelancers.index', $filters))->assertStatus(422)->assertSee('role="alert"', false)->assertDontSee('DO-NOT-RETURN-ON-INVALID-FILTER');
    }

    public function test_client_presentation_is_explicit_escaped_and_has_no_private_target_fields(): void
    {
        $profile = $this->visibleProfile(['bio' => "<script>unsafe()</script>\nSecond line", 'headline' => '<b>Specialist</b>', 'share_account_photo' => false]);
        $owner = $profile->user;
        $owner->forceFill(['email' => 'SECRET-TARGET@example.test', 'avatar_path' => 'users/'.$owner->id.'/SECRET-PATH.png'])->save();
        $this->discoveryClient();
        $response = $this->get(route('client.freelancers.show', $profile->id))->assertOk()->assertSee(e($profile->bio), false)->assertDontSee('<script>unsafe()</script>', false)
            ->assertDontSee($owner->email)->assertDontSee($owner->avatar_path)->assertDontSee($owner->password)->assertDontSee(route('account.photo', $owner));
        $data = $response->viewData('profile');
        $this->assertEqualsCanonicalizing(['id', 'name', 'initials', 'headline', 'bio', 'location', 'hourly_rate', 'formatted_rate', 'portfolio_url', 'skills', 'reputation', 'photo_url'], array_keys($data));
        $this->assertNull($data['photo_url']);
        $this->assertSame(['id', 'name'], array_keys($data['skills'][0]));
    }

    public function test_unsafe_legacy_portfolio_links_are_omitted_and_valid_links_are_safe(): void
    {
        $profile = $this->visibleProfile(['portfolio_url' => 'javascript:alert(1)']);
        $this->discoveryClient();
        $this->get(route('client.freelancers.show', $profile->id))->assertOk()->assertDontSee('javascript:')->assertDontSeeText('Visit portfolio');
        $profile->update(['portfolio_url' => 'http://example.test/work']);
        $this->get(route('client.freelancers.show', $profile->id))->assertOk()->assertSee('href="http://example.test/work"', false)->assertSee('rel="noopener noreferrer nofollow"', false);
    }

    public function test_reading_pages_does_not_change_profile_or_domain_records(): void
    {
        $profile = $this->visibleProfile();
        $before = $profile->getAttributes();
        $this->discoveryClient();
        $this->get(route('client.freelancers.index'))->assertOk();
        $this->get(route('client.freelancers.show', $profile->id))->assertOk();
        $this->assertSame($before, $profile->fresh()->getAttributes());
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('proposals', 0);
        $this->assertDatabaseCount('project_assignments', 0);
    }

    public function test_proposal_profile_links_follow_eligibility_without_changing_history(): void
    {
        $assignment = ProjectAssignment::factory()->create();
        $profile = $this->visibleProfile(['user_id' => $assignment->freelancer_id]);
        $proposalBefore = $assignment->proposal->fresh()->getAttributes();
        $assignmentBefore = $assignment->fresh()->getAttributes();
        $this->actingAs($assignment->project->client);
        $routes = [route('client.projects.proposals.index', $assignment->project), route('client.projects.proposals.show', [$assignment->project, $assignment->proposal])];
        foreach ($routes as $route) {
            $this->get($route)->assertOk()->assertSee(route('client.freelancers.show', $profile->id));
        }
        $profile->forceFill(['is_public' => false])->save();
        foreach ($routes as $route) {
            $this->get($route)->assertOk()->assertDontSee(route('client.freelancers.show', $profile->id));
        }
        $profile->forceFill(['is_public' => true])->save();
        $profile->user->forceFill(['suspended_at' => now()])->save();
        foreach ($routes as $route) {
            $this->get($route)->assertOk()->assertDontSee(route('client.freelancers.show', $profile->id));
        }
        $this->assertSame($proposalBefore, $assignment->proposal->fresh()->getAttributes());
        $this->assertSame($assignmentBefore, $assignment->fresh()->getAttributes());
    }

    public function test_navigation_empty_states_and_project_posting_cta_work(): void
    {
        $this->discoveryClient();
        $this->get(route('client.dashboard'))->assertOk()->assertSee(route('client.freelancers.index'));
        $this->get(route('client.freelancers.index'))->assertOk()->assertSeeText('No profiles match this page.')->assertSee(route('client.projects.create'))->assertSeeText('Clear filters')->assertDontSeeText('Direct hire');
        $this->get(route('client.projects.create'))->assertOk();
    }

    public function test_a_page_uses_bounded_queries_and_never_selects_private_profile_owner_columns(): void
    {
        for ($index = 0; $index < 10; $index++) {
            $this->visibleProfile();
        }
        $this->discoveryClient();
        DB::enableQueryLog();
        $this->get(route('client.freelancers.index'))->assertOk();
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $presentation = $queries->filter(fn ($query) => str_contains($query['query'], 'profile_owner'));
        $this->assertCount(2, $presentation); // One count and one projected page query, not one per profile.
        foreach ($presentation as $query) {
            foreach (['email', 'password', 'remember_token', 'avatar_path'] as $private) {
                $this->assertStringNotContainsString($private, $query['query']);
            }
        }
        $this->assertSame(1, $queries->filter(fn ($query) => str_contains($query['query'], 'inner join "freelancer_profile_skill"'))->count());
    }

    public static function ineligibleTargets(): array
    {
        return array_combine(['private', 'suspended', 'client', 'admin', 'missing'], array_map(fn ($case) => [$case], ['private', 'suspended', 'client', 'admin', 'missing']));
    }

    public static function sortOptions(): array
    {
        return ['newest' => ['newest', [2, 1, 0]], 'name' => ['name', [0, 2, 1]], 'ascending' => ['rate_asc', [0, 1, 2]], 'descending' => ['rate_desc', [1, 0, 2]], 'unrated ties' => ['rating', [2, 1, 0]]];
    }

    public static function invalidFilters(): array
    {
        return [
            'search length' => [['search' => str_repeat('a', 151)]], 'search array' => [['search' => ['bad']]],
            'location length' => [['location' => str_repeat('a', 121)]], 'location array' => [['location' => ['bad']]],
            'unknown skill' => [['skills' => [999999]]], 'duplicate skills' => [['skills' => [1, 1]]], 'skill scalar' => [['skills' => '1']],
            'too many skills' => [['skills' => range(1, 11)]], 'nested skill' => [['skills' => [['bad']]]],
            'negative rate' => [['min_rate' => '-1']], 'rate precision' => [['max_rate' => '1.001']], 'rate range' => [['max_rate' => '10000000000.00']],
            'rate exponent' => [['min_rate' => '1e3']], 'rate array' => [['min_rate' => ['bad']]], 'inverted range' => [['min_rate' => '20', 'max_rate' => '10']],
            'sort injection' => [['sort' => 'email desc']], 'sort array' => [['sort' => ['bad']]],
            'negative page' => [['page' => 0]], 'excess page' => [['page' => 10001]], 'page array' => [['page' => ['bad']]],
        ];
    }
}
