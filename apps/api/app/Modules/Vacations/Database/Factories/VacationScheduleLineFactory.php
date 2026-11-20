<?php

namespace App\Modules\Vacations\Database\Factories;

use App\Modules\Vacations\Infrastructure\Models\VacationSchedule;
use App\Modules\Vacations\Infrastructure\Models\VacationScheduleLine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Línea propuesta del rol anual para una relación laboral nueva, en la campaña de su empresa.
 *
 * @extends Factory<VacationScheduleLine>
 */
class VacationScheduleLineFactory extends Factory
{
    protected $model = VacationScheduleLine::class;

    public function definition(): array
    {
        $employment = VacationRequestFactory::employment();
        $entity = (string) DB::table('employments')->where('id', $employment)->value('legal_entity_id');

        return [
            'vacation_schedule_id' => fn (): string => VacationSchedule::query()->create(['legal_entity_id' => $entity, 'year' => 2027, 'name' => 'Rol 2027'])->id,
            'employment_id' => $employment,
            'starts_on' => '2027-02-01',
            'ends_on' => '2027-02-15',
            'days' => '15',
            'status' => 'proposed',
        ];
    }
}
