<?php

namespace App\Livewire\Forms;

use App\Models\ProjectTask;
use Illuminate\Support\Str;
use Livewire\Form;

class ProjectTaskForm extends Form
{
    public const FIELDS = ['title', 'description', 'due_date', 'milestone_id'];

    public string $title = '';

    public string $description = '';

    public string $due_date = '';

    public string $milestone_id = '';

    public function updating(string $property): void
    {
        abort_unless(in_array($property, self::FIELDS, true), 403);
    }

    public function loadTask(ProjectTask $task): void
    {
        $this->title = $task->title;
        $this->description = $task->description ?? '';
        $this->due_date = $task->due_date->toDateString();
        $this->milestone_id = $task->milestone_id === null ? '' : (string) $task->milestone_id;
    }

    public function details(): array
    {
        $this->title = Str::trim($this->title);
        $this->description = Str::trim($this->description);

        return $this->only(self::FIELDS);
    }
}
