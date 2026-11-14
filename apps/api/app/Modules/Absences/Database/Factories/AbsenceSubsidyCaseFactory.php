<?php

namespace App\Modules\Absences\Database\Factories;

use App\Modules\Absences\Infrastructure\Models\AbsenceRequest;
use App\Modules\Absences\Infrastructure\Models\AbsenceSubsidyCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Expediente de subsidio por presentar de una ausencia nueva (AUS-3).
 *
 * @extends Factory<AbsenceSubsidyCase>
 */
class AbsenceSubsidyCaseFactory extends Factory
{
    protected $model = AbsenceSubsidyCase::class;

    public function definition(): array
    {
        return [
            'absence_request_id' => fn (): string => AbsenceRequest::factory()->create()->id,
            'employment_id' => fn (array $a): string => (string) AbsenceRequest::query()->whereKey($a['absence_request_id'])->value('employment_id'),
            'kind' => 'illness',
            'status' => 'pending_filing',
            'subsidized_days' => '5',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-06-05',
            'year' => 2026,
            'currency' => 'PEN',
        ];
    }
}
