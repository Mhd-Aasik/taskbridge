<?php

namespace App\Livewire\Forms;

use App\Models\Milestone;
use App\Support\MilestoneRules;
use Illuminate\Support\Str;
use Livewire\Form;

class MilestoneForm extends Form
{
    public string $title = '';

    public string $description = '';

    public string $due_date = '';

    public function updating(string $property): void
    {
        abort_unless(in_array($property, MilestoneRules::FIELDS, true), 403);
    }

    public function loadMilestone(Milestone $milestone): void
    {
        $this->title = $milestone->title;
        $this->description = $milestone->description ?? '';
        $this->due_date = $milestone->due_date->toDateString();
    }

    public function details(): array
    {
        $this->title = Str::trim($this->title);
        $this->description = Str::trim($this->description);

        return $this->only(MilestoneRules::FIELDS);
    }
}
