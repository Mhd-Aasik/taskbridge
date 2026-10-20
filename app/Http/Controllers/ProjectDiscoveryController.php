<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Rules\LkrBudget;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectDiscoveryController extends Controller
{
    private const SORTS = [
        'newest' => 'Newest publication',
        'budget_asc' => 'Budget: low to high',
        'budget_desc' => 'Budget: high to low',
        'deadline' => 'Nearest deadline',
    ];

    public function index(Request $request): Response
    {
        $this->authorizeBrowse($request);
        $validator = Validator::make($request->query(), [
            'search' => ['bail', 'nullable', 'string', 'max:150'],
            'category' => ['bail', 'nullable', 'integer', 'min:1', Rule::exists(Category::class, 'id')->where('is_active', true)],
            'min_budget' => ['bail', 'nullable', 'string', 'max:13', new LkrBudget(allowZero: true)],
            'max_budget' => ['bail', 'nullable', 'string', 'max:13', new LkrBudget(allowZero: true)],
            'sort' => ['bail', 'nullable', 'string', Rule::in(array_keys(self::SORTS))],
            'page' => ['bail', 'nullable', 'integer', 'min:1', 'max:10000'],
        ], [
            'category.exists' => 'Choose an active category, or select all categories.',
            'sort.in' => 'Choose one of the available sorting options.',
        ]);
        $validator->after(function ($validator) use ($request): void {
            if (! $validator->errors()->hasAny(['min_budget', 'max_budget'])
                && filled($request->query('min_budget')) && filled($request->query('max_budget'))
                && BigDecimal::of($request->query('min_budget'))->isGreaterThan($request->query('max_budget'))) {
                $validator->errors()->add('max_budget', 'The maximum budget must be at least the minimum budget.');
            }
        });

        $valid = ! $validator->fails();
        $filters = [];
        foreach (['search', 'category', 'min_budget', 'max_budget', 'sort'] as $key) {
            $value = $request->query($key);
            $filters[$key] = is_string($value) ? $value : '';
        }
        $filters['sort'] = array_key_exists($filters['sort'], self::SORTS) ? $filters['sort'] : 'newest';
        $projects = null;

        if ($valid) {
            foreach (['min_budget', 'max_budget'] as $key) {
                if ($filters[$key] !== '') {
                    $filters[$key] = (string) BigDecimal::of($filters[$key])->toScale(2);
                }
            }
            $query = Project::query()->discoverable()->with('category:id,name');
            $this->applyFilters($query, $filters);
            $projects = $query->paginate(10, ['*'], 'page', (int) ($validator->validated()['page'] ?? 1))
                ->appends(array_filter($filters, fn (string $value) => $value !== ''));
        }

        return response()->view('freelancer.projects.index', [
            'projects' => $projects,
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'filters' => $filters,
            'sorts' => self::SORTS,
            'filterErrors' => $validator->errors(),
            'hasFilters' => collect($filters)->except('sort')->contains(fn (string $value) => $value !== '') || $filters['sort'] !== 'newest',
        ], $valid ? 200 : 422);
    }

    public function show(Request $request, string $project): View
    {
        $this->authorizeBrowse($request);
        $project = Project::query()->discoverable()
            ->with(['category:id,name', 'client:id,name'])->findOrFail($project);

        return view('freelancer.projects.show', [
            'project' => $project,
            'deadline' => $project->calendarDeadline(),
            'publicationTime' => $project->published_at->toImmutable()->setTimezone('Asia/Colombo'),
        ]);
    }

    private function authorizeBrowse(Request $request): void
    {
        $user = User::query()->find($request->user()->id);
        abort_unless($user, 403);
        Gate::forUser($user)->authorize('browse', Project::class);
    }

    /** @param Builder<Project> $query */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['search'] !== '') {
            // Treat SQL LIKE wildcards as literal search text on SQLite and MySQL.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(fn (Builder $search) => $search
                ->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]));
        }
        if ($filters['category'] !== '') {
            $query->where('category_id', $filters['category']);
        }
        if ($filters['min_budget'] !== '') {
            $query->where('budget', '>=', $filters['min_budget']);
        }
        if ($filters['max_budget'] !== '') {
            $query->where('budget', '<=', $filters['max_budget']);
        }

        match ($filters['sort']) {
            'budget_asc' => $query->orderBy('budget'),
            'budget_desc' => $query->orderByDesc('budget'),
            'deadline' => $query->orderBy('deadline'),
            default => $query->orderByDesc('published_at'),
        };
        $query->orderByDesc('id');
    }
}
