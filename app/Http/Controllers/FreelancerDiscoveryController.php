<?php

namespace App\Http\Controllers;

use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Rules\LkrBudget;
use App\Support\AccountAccess;
use App\Support\FreelancerDiscovery;
use App\Support\FreelancerProfiles;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FreelancerDiscoveryController extends Controller
{
    private const SORTS = ['newest' => 'Newest publication', 'name' => 'Name', 'rate_asc' => 'Hourly rate: low to high', 'rate_desc' => 'Hourly rate: high to low', 'rating' => 'Released rating: highest first'];

    public function index(Request $request): Response
    {
        $this->authorizeClient();
        $validator = Validator::make($request->query(), [
            'search' => ['bail', 'nullable', 'string', 'max:150'],
            'location' => ['bail', 'nullable', 'string', 'max:120'],
            'skills' => ['bail', 'nullable', 'array', 'max:10'],
            'skills.*' => ['bail', 'required', 'integer', 'distinct', Rule::exists(Skill::class, 'id')],
            'min_rate' => ['bail', 'nullable', 'string', 'max:13', new LkrBudget(allowZero: true)],
            'max_rate' => ['bail', 'nullable', 'string', 'max:13', new LkrBudget(allowZero: true)],
            'sort' => ['bail', 'nullable', 'string', Rule::in(array_keys(self::SORTS))],
            'page' => ['bail', 'nullable', 'integer', 'between:1,10000'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            if (! $validator->errors()->hasAny(['min_rate', 'max_rate']) && filled($request->query('min_rate')) && filled($request->query('max_rate'))
                && BigDecimal::of($request->query('min_rate'))->isGreaterThan($request->query('max_rate'))) {
                $validator->errors()->add('max_rate', 'The maximum hourly rate must be at least the minimum.');
            }
        });
        $valid = ! $validator->fails();
        $filters = [];
        foreach (['search', 'location', 'min_rate', 'max_rate', 'sort'] as $key) {
            $filters[$key] = is_string($request->query($key)) ? $request->query($key) : '';
        }
        $filters['sort'] = array_key_exists($filters['sort'], self::SORTS) ? $filters['sort'] : 'newest';
        $filters['skills'] = is_array($request->query('skills')) ? array_values(array_filter($request->query('skills'), fn ($id) => is_string($id) || is_int($id))) : [];
        $profiles = null;
        if ($valid) {
            $query = FreelancerDiscovery::query();
            if ($filters['search'] !== '') {
                $pattern = FreelancerDiscovery::literalPattern($filters['search']);
                $query->where(fn (Builder $search) => $search->whereRaw("profile_owner.name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("headline LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("bio LIKE ? ESCAPE '!'", [$pattern]));
            }
            if ($filters['location'] !== '') {
                $query->whereRaw("location LIKE ? ESCAPE '!'", [FreelancerDiscovery::literalPattern($filters['location'])]);
            }
            foreach ($filters['skills'] as $skill) {
                $query->whereHas('skills', fn (Builder $skills) => $skills->where('skills.id', $skill));
            }
            foreach (['min_rate' => '>=', 'max_rate' => '<='] as $key => $operator) {
                if ($filters[$key] !== '') {
                    $filters[$key] = (string) BigDecimal::of($filters[$key])->toScale(2);
                    $query->whereNotNull('hourly_rate')->where('hourly_rate', $operator, $filters[$key]);
                }
            }
            match ($filters['sort']) {
                'name' => $query->orderBy('profile_owner.name'),
                'rate_asc' => $query->orderByRaw('hourly_rate IS NULL')->orderBy('hourly_rate'),
                'rate_desc' => $query->orderByRaw('hourly_rate IS NULL')->orderByDesc('hourly_rate'),
                'rating' => $query->orderByRaw('rating_average IS NULL')->orderByDesc('rating_average'),
                default => $query->orderByRaw('first_published_at IS NULL')->orderByDesc('first_published_at'),
            };
            $profiles = $query->orderByDesc('freelancer_profiles.id')->paginate(10, ['*'], 'page', (int) ($validator->validated()['page'] ?? 1))
                ->appends(array_filter($filters, fn ($value) => $value !== '' && $value !== []));
            $profiles->setCollection($profiles->getCollection()->map(fn ($profile) => FreelancerDiscovery::present($profile)));
        }

        return response()->view('client.freelancers.index', [
            'profiles' => $profiles, 'filters' => $filters, 'sorts' => self::SORTS,
            'skills' => Skill::query()->orderBy('name')->orderBy('id')->get(['id', 'name']), 'filterErrors' => $validator->errors(),
        ], $valid ? 200 : 422);
    }

    public function show(int $profile): View
    {
        $user = $this->authorizeClient();
        $profile = FreelancerDiscovery::query()->where('freelancer_profiles.id', $profile)->firstOrFail();
        Gate::forUser($user)->authorize('view', $profile);

        return view('client.freelancers.show', ['profile' => FreelancerDiscovery::present($profile), 'preview' => false]);
    }

    public function preview(): View
    {
        [$user, $profile] = FreelancerProfiles::owned();
        if ($profile) {
            Gate::forUser($user)->authorize('preview', $profile);
            $profile = FreelancerDiscovery::query(eligible: false)->where('freelancer_profiles.id', $profile->id)->firstOrFail();
        }

        return view('client.freelancers.show', ['profile' => $profile ? FreelancerDiscovery::present($profile, preview: true) : null, 'preview' => true]);
    }

    private function authorizeClient(): User
    {
        $user = AccountAccess::account();
        Gate::forUser($user)->authorize('browse', FreelancerProfile::class);

        return $user;
    }
}
