<?php

namespace Database\Factories;

use App\Models\PaymentAcknowledgment;
use App\Models\PaymentReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentAcknowledgment> */
class PaymentAcknowledgmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_report_id' => PaymentReport::factory(),
            'acknowledged_by' => fn (array $attributes) => PaymentReport::findOrFail($attributes['payment_report_id'])->assignment->freelancer_id,
            'acknowledged_at' => CarbonImmutable::now('UTC'),
            'note' => null,
        ];
    }
}
