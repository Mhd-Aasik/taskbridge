<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\PaymentReport;
use App\Models\ProjectAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PaymentReport> */
class PaymentReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_assignment_id' => ProjectAssignment::factory(),
            'amount' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->agreed_budget,
            'currency' => 'LKR',
            'method' => PaymentMethod::BankTransfer,
            'paid_on' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->assigned_at->setTimezone('Asia/Colombo')->toDateString(),
            'reference' => null,
            'note' => null,
            'reported_by' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->project->client_id,
            'reported_at' => CarbonImmutable::now('UTC'),
            'submission_id' => (string) Str::uuid(),
        ];
    }
}
