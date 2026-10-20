<?php

namespace App\Livewire\Forms;

use App\Support\ProposalSubmissionRules;
use Illuminate\Support\Str;
use Livewire\Form;

class ProposalSubmissionForm extends Form
{
    public string $cover_letter = '';

    public string $proposed_budget = '';

    public string $proposed_deadline = '';

    public function updating(string $property): void
    {
        abort_unless(in_array($property, ProposalSubmissionRules::FIELDS, true), 403);
    }

    public function details(): array
    {
        $this->cover_letter = Str::trim($this->cover_letter);

        return $this->only(ProposalSubmissionRules::FIELDS);
    }
}
