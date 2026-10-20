<?php

namespace App\Livewire\Forms;

use App\Models\Project;
use App\Support\ProjectRules;
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
        return ProjectRules::earliestDeadline();
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
        $rawBudget = (string) $project->getRawOriginal('budget');
        $this->budget = preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $rawBudget)
            ? $this->normaliseBudget($rawBudget) : $rawBudget;
        $this->deadline = $project->calendarDeadline()?->toDateString() ?? (string) $project->getRawOriginal('deadline');
    }

    public function validatedDetails(): array
    {
        $this->title = trim($this->title);
        $this->description = trim($this->description);
        $details = $this->validate();

        $details['budget'] = $this->normaliseBudget($details['budget']);

        return $details;
    }

    protected function normaliseBudget(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (ltrim($whole, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
    }

    protected function rules(): array
    {
        return ProjectRules::rules();
    }

    protected function messages(): array
    {
        return ProjectRules::messages();
    }
}
