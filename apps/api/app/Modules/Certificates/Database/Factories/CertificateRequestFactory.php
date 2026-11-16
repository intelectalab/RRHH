<?php

namespace App\Modules\Certificates\Database\Factories;

use App\Modules\Certificates\Infrastructure\Models\CertificateRequest;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @extends Factory<CertificateRequest>
 */
class CertificateRequestFactory extends Factory
{
    protected $model = CertificateRequest::class;

    public function definition(): array
    {
        return [
            'employment_id' => fn (): string => self::employment(),
            'kind' => 'employment_certificate',
            'status' => 'requested',
        ];
    }

    public static function employment(): string
    {
        $tenant = TenancyScope::requireTenant();
        $entity = (string) Str::uuid();
        DB::table('legal_entities')->insert([
            'id' => $entity, 'tenant_id' => $tenant, 'legal_name' => 'Empresa '.Str::random(6).' S.A.C.', 'country_code' => 'PE',
            'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
        ]);
        $person = (string) DB::table('people')->insertGetId(['tenant_id' => $tenant, 'first_names' => 'Persona', 'paternal_surname' => Str::random(6)]);

        return (string) DB::table('employments')->insertGetId([
            'tenant_id' => $tenant, 'person_id' => $person, 'legal_entity_id' => $entity,
            'employee_code' => 'C'.random_int(100000, 999999), 'hire_date' => '2025-01-01', 'status' => 'active',
        ]);
    }
}
