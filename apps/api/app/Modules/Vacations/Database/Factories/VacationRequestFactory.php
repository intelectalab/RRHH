<?php

namespace App\Modules\Vacations\Database\Factories;

use App\Modules\Vacations\Infrastructure\Models\VacationRequest;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Solicitud de vacaciones en borrador de una relación laboral nueva (sembrada en base: el módulo de
 * vacaciones no usa los modelos de Core HR).
 *
 * @extends Factory<VacationRequest>
 */
class VacationRequestFactory extends Factory
{
    protected $model = VacationRequest::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => self::employment(),
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-05',
            'days' => '5',
            'calendar_days' => '5',
            'kind' => 'leave',
            'status' => 'draft',
        ];
    }

    /** Relación laboral activa de una persona nueva en una empresa nueva de la organización actual. */
    public static function employment(): string
    {
        $tenant = TenancyScope::requireTenant();
        $entity = (string) Str::uuid();
        DB::table('legal_entities')->insert([
            'id' => $entity, 'tenant_id' => $tenant, 'legal_name' => 'Empresa '.Str::random(6).' S.A.C.', 'country_code' => 'PE',
            'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
        ]);
        $person = (string) DB::table('people')->insertGetId([
            'tenant_id' => $tenant, 'first_names' => 'Persona', 'paternal_surname' => Str::random(6),
        ]);

        return (string) DB::table('employments')->insertGetId([
            'tenant_id' => $tenant, 'person_id' => $person, 'legal_entity_id' => $entity,
            'employee_code' => 'V'.random_int(100000, 999999), 'hire_date' => '2025-01-01', 'status' => 'active',
        ]);
    }
}
