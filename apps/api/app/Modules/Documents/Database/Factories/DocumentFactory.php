<?php

namespace App\Modules\Documents\Database\Factories;

use App\Modules\Documents\Infrastructure\Models\Document;
use App\Modules\Documents\Infrastructure\Models\DocumentCategory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Documento vigente del legajo de una relación laboral nueva (persona, empresa y relación sembradas en base:
 * el módulo documental no usa los modelos de Core HR ni de Organización).
 *
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'document_category_id' => fn (): string => DocumentCategory::factory()->create()->id,
            'title' => 'Documento '.Str::random(6),
            'classification' => 'internal',
            'source' => 'upload',
            'visible_to_employee' => true,
            'employment_id' => fn (): string => self::employment(),
            'owner_person_id' => fn (array $a): ?string => DB::table('employments')->where('id', $a['employment_id'])->value('person_id'),
            'legal_entity_id' => fn (array $a): ?string => DB::table('employments')->where('id', $a['employment_id'])->value('legal_entity_id'),
        ];
    }

    public static function employment(): string
    {
        $tenant = TenancyScope::requireTenant();
        $legalEntity = (string) Str::uuid();
        DB::table('legal_entities')->insert([
            'id' => $legalEntity, 'tenant_id' => $tenant, 'legal_name' => 'Empresa '.Str::random(6).' S.A.C.',
            'country_code' => 'PE', 'tax_id' => '20'.random_int(100000000, 999999999), 'currency' => 'PEN',
            'timezone' => 'America/Lima', 'status' => 'active',
        ]);
        $person = (string) Str::uuid();
        DB::table('people')->insert(['id' => $person, 'tenant_id' => $tenant, 'first_names' => 'Persona', 'paternal_surname' => 'Legajo '.Str::random(5)]);
        $employment = (string) Str::uuid();
        DB::table('employments')->insert([
            'id' => $employment, 'tenant_id' => $tenant, 'person_id' => $person, 'legal_entity_id' => $legalEntity,
            'employee_code' => 'D'.Str::upper(Str::random(7)), 'hire_date' => '2025-01-01', 'status' => 'active',
        ]);

        return $employment;
    }
}
