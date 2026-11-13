<?php

namespace Database\Seeders;

use App\Modules\CoreHR\Application\EmploymentService;
use App\Modules\Organization\Application\JobProfileService;
use App\Modules\Organization\Application\OrgUnitService;
use App\Modules\Organization\Application\OrgUnitTypeCatalog;
use App\Modules\Organization\Application\PositionService;
use App\Modules\Organization\Application\SiteService;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\OrgUnit;
use App\Modules\Organization\Infrastructure\Models\Position;
use App\Shared\Labor\ContractTypeSource;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Estructura y colaboradores de la organización demo (COL-2): unidades, sedes, puestos, posiciones con su
 * línea de reporte y 18 ingresos coherentes, para que el directorio, el organigrama, las posiciones y el
 * listado de colaboradores muestren datos. Las personas son inventadas y sus documentos son ficticios
 * (empiezan con 0000); no se siembran sueldos. Idempotente: si ya hay colaboradores, no hace nada.
 */
class DemoPeopleSeeder extends Seeder
{
    private const START = '2024-01-01';

    public function run(): void
    {
        $tenantId = DB::table('tenants')->where('slug', DemoOrganizationSeeder::SLUG)->value('id');
        if ($tenantId === null) {
            return;
        }

        TenancyScope::runAs((string) $tenantId, function (): void {
            if (DB::table('employments')->exists()) {
                return;
            }
            $andes = LegalEntity::query()->where('tax_id', '20999999901')->first();
            $inti = LegalEntity::query()->where('tax_id', '20999999902')->first();
            if (! $andes instanceof LegalEntity || ! $inti instanceof LegalEntity) {
                return;
            }
            DB::table('legal_entity_regimes')->whereIn('legal_entity_id', [$andes->id, $inti->id])->where('valid_from', '>', self::START)
                ->update(['valid_from' => self::START]);

            app(OrgUnitTypeCatalog::class)->ensureDefaults();
            app(ContractTypeSource::class)->ensureCountryTypes();
            $types = DB::table('org_unit_types')->pluck('id', 'code');
            $indefinite = (string) DB::table('contract_types')->where('code', 'indeterminado')->value('id');

            $sites = app(SiteService::class);
            $ate = $sites->create(['legal_entity_id' => $andes->id, 'code' => 'ATE', 'name' => 'Sede Ate', 'kind' => 'headquarters',
                'address' => 'Av. Nicolás Ayllón 4520, Ate, Lima', 'timezone' => 'America/Lima'])->id;
            $lurin = $sites->create(['legal_entity_id' => $inti->id, 'code' => 'LURIN', 'name' => 'Base Lurín', 'kind' => 'plant',
                'address' => 'Antigua Panamericana Sur km 32, Lurín, Lima', 'timezone' => 'America/Lima'])->id;

            $units = app(OrgUnitService::class);
            $unit = fn (string $code, string $name, string $type, string $entity, ?string $parent = null): OrgUnit => $units->create([
                'code' => $code, 'name' => $name, 'type_id' => (string) $types[$type], 'legal_entity_id' => $entity,
                'parent_id' => $parent, 'effective_from' => self::START,
            ], null);
            $gg = $unit('gerencia_general', 'Gerencia General', 'management', $andes->id);
            $adm = $unit('administracion', 'Administración y Finanzas', 'area', $andes->id, $gg->id);
            $rrhh = $unit('recursos_humanos', 'Recursos Humanos', 'area', $andes->id, $gg->id);
            $com = $unit('comercial', 'Comercial', 'area', $andes->id, $gg->id);
            $ope = $unit('operaciones_inti', 'Operaciones de transporte', 'management', $inti->id);

            $jobs = app(JobProfileService::class);
            $job = fn (string $code, string $name): string => $jobs->create(['code' => $code, 'name' => $name, 'trust_classification' => 'none'], null)->id;
            $profiles = [
                'GG' => $job('GG', 'Gerente general'), 'JRH' => $job('JRH', 'Jefe de Recursos Humanos'), 'ARH' => $job('ARH', 'Analista de Recursos Humanos'),
                'JAF' => $job('JAF', 'Jefe de Administración y Finanzas'), 'CONT' => $job('CONT', 'Contador'), 'ASIS' => $job('ASIS', 'Asistente administrativo'),
                'JCOM' => $job('JCOM', 'Jefe comercial'), 'EVEN' => $job('EVEN', 'Ejecutivo de ventas'), 'JOPE' => $job('JOPE', 'Jefe de operaciones'),
                'COND' => $job('COND', 'Conductor'), 'ALM' => $job('ALM', 'Operario de almacén'),
            ];

            $positions = app(PositionService::class);
            $seat = function (string $code, string $profile, OrgUnit $unit, string $entity, string $site, ?Position $boss = null, int $seats = 1) use ($positions, $profiles): Position {
                $p = $positions->create([
                    'code' => $code, 'title' => (string) DB::table('job_profiles')->where('id', $profiles[$profile])->value('name'),
                    'job_profile_id' => $profiles[$profile], 'legal_entity_id' => $entity, 'org_unit_id' => $unit->id, 'site_id' => $site,
                    'reports_to_position_id' => $boss?->id, 'valid_from' => self::START, 'is_shared' => $seats > 1, 'seats' => $seats,
                ], null);

                return $positions->transition($p, 'approve', null, null, null);
            };
            $pGG = $seat('P-GG', 'GG', $gg, $andes->id, $ate);
            $pJRH = $seat('P-JRH', 'JRH', $rrhh, $andes->id, $ate, $pGG);
            $pARH = $seat('P-ARH', 'ARH', $rrhh, $andes->id, $ate, $pJRH, 2);
            $pJAF = $seat('P-JAF', 'JAF', $adm, $andes->id, $ate, $pGG);
            $pCONT = $seat('P-CONT', 'CONT', $adm, $andes->id, $ate, $pJAF);
            $pASIS = $seat('P-ASIS', 'ASIS', $adm, $andes->id, $ate, $pJAF);
            $pJCOM = $seat('P-JCOM', 'JCOM', $com, $andes->id, $ate, $pGG);
            $pEVEN = $seat('P-EVEN', 'EVEN', $com, $andes->id, $ate, $pJCOM, 5);
            $pJOPE = $seat('P-JOPE', 'JOPE', $ope, $inti->id, $lurin);
            $pCOND = $seat('P-COND', 'COND', $ope, $inti->id, $lurin, $pJOPE, 4);
            $pALM = $seat('P-ALM', 'ALM', $ope, $inti->id, $lurin, $pJOPE, 2);
            foreach ([[$gg, $pGG], [$rrhh, $pJRH], [$adm, $pJAF], [$com, $pJCOM], [$ope, $pJOPE]] as [$u, $p]) {
                $units->update($u, ['head_position_id' => $p->id, 'effective_from' => self::START], null);
            }

            $hires = app(EmploymentService::class);
            $n = 100;
            $people = [
                ['Mariela', 'Paredes', 'Salas', $pGG, '2024-01-08'], ['Jorge', 'Castañeda', 'Ruiz', $pJRH, '2024-02-01'],
                ['Lucía', 'Fernández', 'Quispe', $pARH, '2024-03-04'], ['Diego', 'Rojas', 'Huamán', $pARH, '2025-06-02'],
                ['Patricia', 'Medina', 'Torres', $pJAF, '2024-01-15'], ['Raúl', 'Valdivia', 'Chávez', $pCONT, '2024-04-01'],
                ['Sofía', 'Gutiérrez', 'León', $pASIS, '2025-01-06'], ['Andrés', 'Salazar', 'Pinto', $pJCOM, '2024-02-12'],
                ['Valeria', 'Ramos', 'Cárdenas', $pEVEN, '2024-05-06'], ['Martín', 'Ortiz', 'Flores', $pEVEN, '2024-08-01'],
                ['Gabriela', 'Núñez', 'Vargas', $pEVEN, '2025-03-03'], ['Hugo', 'Mendoza', 'Lazo', $pEVEN, '2025-09-01'],
                ['Carmen', 'Delgado', 'Rivas', $pJOPE, '2024-01-10'], ['Wilmer', 'Ccori', 'Apaza', $pCOND, '2024-03-18'],
                ['Edwin', 'Huanca', 'Mamani', $pCOND, '2024-07-01'], ['Rodrigo', 'Villanueva', 'Soto', $pCOND, '2025-02-03'],
                ['Nancy', 'Condori', 'Ticona', $pALM, '2024-06-03'], ['Elías', 'Benites', 'Zapata', $pALM, now()->addDays(14)->toDateString()],
            ];
            foreach ($people as [$first, $paternal, $maternal, $position, $hireDate]) {
                $n++;
                $hires->hire([
                    'person' => ['first_names' => $first, 'paternal_surname' => $paternal, 'maternal_surname' => $maternal,
                        'document_type' => 'dni', 'document_number' => '0000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)],
                    'legal_entity_id' => $position->legal_entity_id,
                    'hire_date' => $hireDate,
                    'position_id' => $position->id,
                    'contract_type_id' => $indefinite,
                    'work_email' => $this->email($first, $paternal),
                ], null, false);
            }
        });
    }

    private function email(string $first, string $last): string
    {
        $plain = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $first.'.'.$last));

        return preg_replace('/[^a-z.]/', '', $plain).'@demo.gestiahr.test';
    }
}
