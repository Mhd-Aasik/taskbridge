<?php

namespace App\Livewire\Forms;

use App\Models\Category;
use App\Models\Project;
use App\Rules\LkrBudget;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ProjectDraftForm extends Form
{
    public string $title = '';

    public string $description = '';

    public string $category_id = '';

    public string $budget = '';

    public string $deadline = '';

    public static function earliestDeadline(): string
    {
        return CarbonImmutable::today('Asia/Colombo')->addDay()->toDateString();
    }

    public function updating(string $property): void
    {
        abort_unless(array_key_exists($property, $this->rules()), 403);
    }

    public function loadProject(Project $project): void
    {
        $this->title = $project->title;
        $this->description = $project->description;
        $this->category_id = (string) $project->category_id;
        $this->budget = $project->budget;
        $this->deadline = $project->deadline->toDateString();
    }

    public function validatedDetails(): array
    {
        $this->title = trim($this->title);
        $this->description = trim($this->description);
        $details = $this->validate();

        [$whole, $fraction] = array_pad(explode('.', $details['budget'], 2), 2, '');
        $details['budget'] = ltrim($whole, '0').'.'.str_pad($fraction, 2, '0');

        return $details;
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:10000'],
            'category_id' => ['required', 'integer', Rule::exists(Category::class, 'id')->where('is_active', true)],
            'budget' => ['bail', 'required', 'string', new LkrBudget],
            'deadline' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after:'.CarbonImmutable::today('Asia/Colombo')->toDateString()],
        ];
    }

    protected function messages(): array
    {
        return [
            'category_id.exists' => 'Choose an active project category.',
            'deadline.after' => 'Choose a deadline tomorrow or later in Sri Lanka (Asia/Colombo).',
        ];
    }
}
