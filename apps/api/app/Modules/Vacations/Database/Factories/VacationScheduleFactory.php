<?php

namespace App\Modules\Vacations\Database\Factories;

use App\Modules\Vacations\Infrastructure\Models\VacationSchedule;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Campaña del rol anual de una empresa nueva de la organización actual.
 *
 * @extends Factory<VacationSchedule>
 */
class VacationScheduleFactory extends Factory
{
    protected $model = VacationSchedule::class;

    public function definition(): array
    {
        return [
            'legal_entity_id' => fn (): string => self::legalEntity(),
            'year' => 2027,
            'name' => 'Rol de vacaciones 2027',
            'status' => 'open',
        ];
    }

    public static function legalEntity(): string
    {
        $entity = (string) Str::uuid();
        DB::table('legal_entities')->insert([
            'id' => $entity, 'tenant_id' => TenancyScope::requireTenant(), 'legal_name' => 'Empresa '.Str::random(6).' S.A.C.', 'country_code' => 'PE',
            'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
        ]);

        return $entity;
    }
}
