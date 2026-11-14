<?php

namespace App\Modules\Absences\Database\Factories;

use App\Modules\Absences\Infrastructure\Models\AbsenceRequest;
use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ausencia en borrador de una relación laboral nueva (sembrada en base: el módulo de ausencias no usa los
 * modelos de Core HR) con un tipo propio de la organización.
 *
 * @extends Factory<AbsenceRequest>
 */
class AbsenceRequestFactory extends Factory
{
    protected $model = AbsenceRequest::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => self::employment(),
            'absence_type_id' => fn (): string => AbsenceType::factory()->create()->id,
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-06-01',
            'starts_at' => '2026-06-01 05:00:00+00',
            'ends_at' => '2026-06-02 05:00:00+00',
            'days' => '1',
            'calendar_days' => '1',
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
            'employee_code' => 'A'.random_int(100000, 999999), 'hire_date' => '2025-01-01', 'status' => 'active',
        ]);
    }
}
