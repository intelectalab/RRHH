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
 * Estructura y colaboradores de la organización demo (COL-2, IMP-5): unidades (Gerencia General, RRHH,
 * Administración, Comercial —ventas—, Tecnología y Operaciones), sedes, puestos, posiciones con su línea de
 * reporte, una vacante en reclutamiento y 22 ingresos coherentes con jefaturas, contratos y sueldo.
 *
 * - Todas las fechas son relativas al día de carga (DemoDates): la historia siempre «termina hoy» y los
 *   vencimientos, fines de contrato e ingresos futuros caen cerca, sin importar cuándo se siembre.
 * - Las personas son inventadas, sus documentos son ficticios (empiezan con 0000) y los sueldos son de
 *   ejemplo por puesto (no son parámetros legales).
 * - Idempotente: si ya hay colaboradores, no hace nada. DemoStorySeeder completa la historia (vacaciones,
 *   ausencias, movimientos y un cese) y cada fase posterior amplía la semilla con sus datos.
 */
class DemoPeopleSeeder extends Seeder
{
    /** Sueldo mensual de ejemplo por puesto (PEN). */
    private const SALARY = [
        'GG' => '18000.00', 'JRH' => '9500.00', 'ARH' => '4200.00', 'SRH' => '6200.00', 'JAF' => '9800.00', 'CONT' => '5600.00',
        'ASIS' => '2600.00', 'JCOM' => '9000.00', 'EVEN' => '3200.00', 'JTI' => '11000.00', 'DEV' => '6500.00', 'ANL' => '5200.00',
        'JOPE' => '7800.00', 'COND' => '2900.00', 'ALM' => '2100.00',
    ];

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
            $start = DemoDates::start();
            DB::table('legal_entity_regimes')->whereIn('legal_entity_id', [$andes->id, $inti->id])->where('valid_from', '>', $start)
                ->update(['valid_from' => $start]);

            app(OrgUnitTypeCatalog::class)->ensureDefaults();
            app(ContractTypeSource::class)->ensureCountryTypes();
            $types = DB::table('org_unit_types')->pluck('id', 'code');
            $contractTypes = DB::table('contract_types')->pluck('id', 'code');

            $sites = app(SiteService::class);
            $ate = $sites->create(['legal_entity_id' => $andes->id, 'code' => 'ATE', 'name' => 'Sede Ate', 'kind' => 'headquarters',
                'address' => 'Av. Nicolás Ayllón 4520, Ate, Lima', 'timezone' => 'America/Lima'])->id;
            $lurin = $sites->create(['legal_entity_id' => $inti->id, 'code' => 'LURIN', 'name' => 'Base Lurín', 'kind' => 'plant',
                'address' => 'Antigua Panamericana Sur km 32, Lurín, Lima', 'timezone' => 'America/Lima'])->id;

            $units = app(OrgUnitService::class);
            $unit = fn (string $code, string $name, string $type, string $entity, ?string $parent = null): OrgUnit => $units->create([
                'code' => $code, 'name' => $name, 'type_id' => (string) $types[$type], 'legal_entity_id' => $entity,
                'parent_id' => $parent, 'effective_from' => $start,
            ], null);
            $gg = $unit('gerencia_general', 'Gerencia General', 'management', $andes->id);
            $adm = $unit('administracion', 'Administración y Finanzas', 'area', $andes->id, $gg->id);
            $rrhh = $unit('recursos_humanos', 'Recursos Humanos', 'area', $andes->id, $gg->id);
            $com = $unit('comercial', 'Comercial y Ventas', 'area', $andes->id, $gg->id);
            $ti = $unit('tecnologia', 'Tecnología', 'area', $andes->id, $gg->id);
            $ope = $unit('operaciones_inti', 'Operaciones de transporte', 'management', $inti->id);

            $jobs = app(JobProfileService::class);
            $job = fn (string $code, string $name): string => $jobs->create(['code' => $code, 'name' => $name, 'trust_classification' => 'none'], null)->id;
            $profiles = [
                'GG' => $job('GG', 'Gerente general'), 'JRH' => $job('JRH', 'Jefe de Recursos Humanos'), 'ARH' => $job('ARH', 'Analista de Recursos Humanos'),
                'SRH' => $job('SRH', 'Especialista de Recursos Humanos'),
                'JAF' => $job('JAF', 'Jefe de Administración y Finanzas'), 'CONT' => $job('CONT', 'Contador'), 'ASIS' => $job('ASIS', 'Asistente administrativo'),
                'JCOM' => $job('JCOM', 'Jefe comercial'), 'EVEN' => $job('EVEN', 'Ejecutivo de ventas'),
                'JTI' => $job('JTI', 'Jefe de Tecnología'), 'DEV' => $job('DEV', 'Desarrollador de software'), 'ANL' => $job('ANL', 'Analista de datos'),
                'JOPE' => $job('JOPE', 'Jefe de operaciones'), 'COND' => $job('COND', 'Conductor'), 'ALM' => $job('ALM', 'Operario de almacén'),
            ];

            $positions = app(PositionService::class);
            $seat = function (string $code, string $profile, OrgUnit $unit, string $entity, string $site, ?Position $boss = null, int $seats = 1) use ($positions, $profiles, $start): Position {
                $p = $positions->create([
                    'code' => $code, 'title' => (string) DB::table('job_profiles')->where('id', $profiles[$profile])->value('name'),
                    'job_profile_id' => $profiles[$profile], 'legal_entity_id' => $entity, 'org_unit_id' => $unit->id, 'site_id' => $site,
                    'reports_to_position_id' => $boss?->id, 'valid_from' => $start, 'is_shared' => $seats > 1, 'seats' => $seats,
                ], null);

                return $positions->transition($p, 'approve', null, null, null);
            };
            $pGG = $seat('P-GG', 'GG', $gg, $andes->id, $ate);
            $pJRH = $seat('P-JRH', 'JRH', $rrhh, $andes->id, $ate, $pGG);
            $pARH = $seat('P-ARH', 'ARH', $rrhh, $andes->id, $ate, $pJRH, 2);
            // Plaza libre a la que se promueve a un analista (movimiento programado de DemoStorySeeder).
            $seat('P-SRH', 'SRH', $rrhh, $andes->id, $ate, $pJRH);
            $pJAF = $seat('P-JAF', 'JAF', $adm, $andes->id, $ate, $pGG);
            $pCONT = $seat('P-CONT', 'CONT', $adm, $andes->id, $ate, $pJAF);
            $pASIS = $seat('P-ASIS', 'ASIS', $adm, $andes->id, $ate, $pJAF);
            $pJCOM = $seat('P-JCOM', 'JCOM', $com, $andes->id, $ate, $pGG);
            $pEVEN = $seat('P-EVEN', 'EVEN', $com, $andes->id, $ate, $pJCOM, 5);
            $pJTI = $seat('P-JTI', 'JTI', $ti, $andes->id, $ate, $pGG);
            $pDEV = $seat('P-DEV', 'DEV', $ti, $andes->id, $ate, $pJTI, 2);
            // Vacante en reclutamiento: punto de partida del recorrido vacante → candidato → contratación (ATS).
            $pANL = $seat('P-ANL', 'ANL', $ti, $andes->id, $ate, $pJTI);
            $positions->transition($pANL, 'open-recruitment', null, null, null);
            $pJOPE = $seat('P-JOPE', 'JOPE', $ope, $inti->id, $lurin);
            $pCOND = $seat('P-COND', 'COND', $ope, $inti->id, $lurin, $pJOPE, 4);
            $pALM = $seat('P-ALM', 'ALM', $ope, $inti->id, $lurin, $pJOPE, 3);
            foreach ([[$gg, $pGG], [$rrhh, $pJRH], [$adm, $pJAF], [$com, $pJCOM], [$ti, $pJTI], [$ope, $pJOPE]] as [$u, $p]) {
                $units->update($u, ['head_position_id' => $p->id, 'effective_from' => $start], null);
            }

            $hires = app(EmploymentService::class);
            $n = 100;
            $profileCode = array_flip($profiles);
            // [nombres, paterno, materno, posición, ingreso (meses atrás, día) o null = futuro, contrato a plazo: [tipo, fin en días desde hoy] ]
            $people = [
                ['Mariela', 'Paredes', 'Salas', $pGG, [30, 8]], ['Jorge', 'Castañeda', 'Ruiz', $pJRH, [29, 1]],
                ['Lucía', 'Fernández', 'Quispe', $pARH, [28, 4]], ['Diego', 'Rojas', 'Huamán', $pARH, [15, 2]],
                ['Patricia', 'Medina', 'Torres', $pJAF, [30, 15]], ['Raúl', 'Valdivia', 'Chávez', $pCONT, [27, 1]],
                ['Sofía', 'Gutiérrez', 'León', $pASIS, [20, 6]], ['Andrés', 'Salazar', 'Pinto', $pJCOM, [29, 12]],
                ['Valeria', 'Ramos', 'Cárdenas', $pEVEN, [26, 6]], ['Martín', 'Ortiz', 'Flores', $pEVEN, [23, 1]],
                ['Gabriela', 'Núñez', 'Vargas', $pEVEN, [18, 3]], ['Hugo', 'Mendoza', 'Lazo', $pEVEN, [8, 1], ['necesidad_mercado', 90]],
                ['Ricardo', 'Aguilar', 'Montes', $pJTI, [24, 5]], ['Daniela', 'Cornejo', 'Bravo', $pDEV, [14, 1]],
                ['Iván', 'Prado', 'Espinoza', $pDEV, [2, 3], ['inicio_actividad', 160]],
                ['Carmen', 'Delgado', 'Rivas', $pJOPE, [30, 10]], ['Wilmer', 'Ccori', 'Apaza', $pCOND, [28, 18]],
                ['Edwin', 'Huanca', 'Mamani', $pCOND, [24, 1]], ['Rodrigo', 'Villanueva', 'Soto', $pCOND, [9, 3], ['necesidad_mercado', 20]],
                ['Nancy', 'Condori', 'Ticona', $pALM, [25, 3]], ['Kevin', 'Paucar', 'Rojas', $pALM, [14, 7]],
                ['Elías', 'Benites', 'Zapata', $pALM, null],
            ];
            foreach ($people as $row) {
                [$first, $paternal, $maternal, $position, $when] = $row;
                $fixed = $row[5] ?? null;
                $n++;
                $input = [
                    'person' => ['first_names' => $first, 'paternal_surname' => $paternal, 'maternal_surname' => $maternal,
                        'document_type' => 'dni', 'document_number' => '0000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT)],
                    'legal_entity_id' => $position->legal_entity_id,
                    'hire_date' => $when === null ? DemoDates::inDays(14) : DemoDates::monthsAgo($when[0], $when[1]),
                    'position_id' => $position->id,
                    'contract_type_id' => (string) $contractTypes[$fixed === null ? 'indeterminado' : $fixed[0]],
                    'work_email' => $this->email($first, $paternal),
                    'base_salary' => ['amount' => self::SALARY[$profileCode[$position->job_profile_id]], 'currency' => 'PEN'],
                ];
                if ($fixed !== null) {
                    $input['contract_end_date'] = DemoDates::inDays($fixed[1]);
                    $input['contract_objective_cause'] = $fixed[0] === 'inicio_actividad'
                        ? 'Implementación del nuevo sistema de ventas en línea de la empresa.'
                        : 'Incremento temporal de la demanda de la campaña de fin de año.';
                }
                $hires->hire($input, null, true);
            }
        });
    }

    private function email(string $first, string $last): string
    {
        $plain = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $first.'.'.$last));

        return preg_replace('/[^a-z.]/', '', $plain).'@demo.gestiahr.test';
    }
}
