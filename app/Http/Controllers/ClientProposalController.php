<?php

namespace App\Http\Controllers;

use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientProposalController extends Controller
{
    private const SORTS = [
        'newest' => 'Newest submission',
        'budget_asc' => 'Lowest offer',
        'budget_desc' => 'Highest offer',
        'deadline' => 'Earliest completion',
    ];

    public function index(Request $request, Project $project): Response
    {
        [, $project] = $this->context($request, $project);
        $statuses = collect(ProposalStatus::cases())->mapWithKeys(fn (ProposalStatus $status) => [$status->value => Str::headline($status->value)])->all();
        $validator = Validator::make($request->query(), [
            'status' => ['bail', 'nullable', 'string', Rule::in(array_keys($statuses))],
            'sort' => ['bail', 'nullable', 'string', Rule::in(array_keys(self::SORTS))],
            'page' => ['bail', 'nullable', 'integer', 'min:1', 'max:10000'],
        ], [
            'status.in' => 'Choose an available proposal status, or select all statuses.',
            'sort.in' => 'Choose one of the available sorting options.',
        ]);
        $valid = ! $validator->fails();
        $filters = [];
        foreach (['status', 'sort'] as $key) {
            $value = $request->query($key);
            $filters[$key] = is_string($value) ? $value : '';
        }
        $filters['sort'] = array_key_exists($filters['sort'], self::SORTS) ? $filters['sort'] : 'newest';
        $proposals = null;
        if ($valid) {
            $query = $project->proposals()->with('freelancer:id,name');
            if ($filters['status'] !== '') {
                $query->where('status', $filters['status']);
            }
            match ($filters['sort']) {
                'budget_asc' => $query->orderBy('proposed_budget'),
                'budget_desc' => $query->orderByDesc('proposed_budget'),
                'deadline' => $query->orderBy('proposed_deadline'),
                default => $query->orderByDesc('submitted_at'),
            };
            $proposals = $query->orderByDesc('id')->paginate(10, ['*'], 'page', (int) ($validator->validated()['page'] ?? 1))
                ->appends(array_filter($filters, fn (string $value) => $value !== ''));
        }

        return response()->view('client.proposals.index', [
            'project' => $project->load('assignment.freelancer:id,name')->loadCount('proposals'),
            'proposals' => $proposals,
            'filters' => $filters,
            'sorts' => self::SORTS,
            'statuses' => $statuses,
            'filterErrors' => $validator->errors(),
        ], $valid ? 200 : 422);
    }

    public function show(Request $request, Project $project, Proposal $proposal): View
    {
        [$user, $project] = $this->context($request, $project);
        // Re-resolve through the parent even after Laravel's scoped route binding.
        $proposal = $project->proposals()->with('freelancer:id,name')->findOrFail($proposal->id);
        Gate::forUser($user)->authorize('review', [$proposal, $project]);

        return view('client.proposals.show', ['project' => $project->load(['category', 'assignment.freelancer:id,name']), 'proposal' => $proposal]);
    }

    private function context(Request $request, Project $project): array
    {
        $user = User::query()->find($request->user()->id);
        abort_unless($user, 403);
        $project = Project::query()->findOrFail($project->id);
        Gate::forUser($user)->authorize('view', $project);
        Gate::forUser($user)->authorize('reviewAny', [Proposal::class, $project]);

        return [$user, $project];
    }
}
