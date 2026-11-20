<?php

namespace Database\Seeders;

use App\Modules\Absences\Application\AbsenceRequestService;
use App\Modules\CoreHR\Application\PersonnelActions;
use App\Modules\CoreHR\Application\TerminationReasonCatalog;
use App\Modules\CoreHR\Application\TerminationService;
use App\Modules\CoreHR\Infrastructure\Models\Employment;
use App\Modules\Vacations\Application\VacationAccrual;
use App\Modules\Vacations\Application\VacationRequestService;
use App\Modules\Vacations\Application\VacationScheduling;
use App\Modules\Vacations\Infrastructure\Models\VacationRequest;
use App\Modules\Vacations\Infrastructure\Models\VacationSchedule;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * IMP-5 · Historia de la organización demo sobre los colaboradores de DemoPeopleSeeder, con fechas relativas
 * al día de carga y por los mismos servicios que usa la aplicación (sin atajos en la base):
 *
 * - Vacaciones gozadas (registradas por RRHH), una solicitud en aprobación del jefe y una ya aprobada a futuro.
 * - Una licencia sin goce en curso (aparece en «ausentes hoy»).
 * - Una promoción programada a la plaza libre de Especialista de RRHH (pasa por el flujo de movimientos).
 * - Un cese por renuncia del mes pasado (offboarding), que deja libre su plaza en almacén.
 *
 * Idempotente: si la organización demo ya tiene solicitudes de vacaciones, no hace nada.
 */
class DemoStorySeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = DB::table('tenants')->where('slug', DemoOrganizationSeeder::SLUG)->value('id');
        if ($tenantId === null) {
            return;
        }

        // db:seed desprotege los modelos (Model::unguard); los servicios de la aplicación cuentan con la protección
        // de atributos (descartan claves que no son columnas), así que se restaura mientras corren.
        $wasUnguarded = Model::isUnguarded();
        Model::reguard();
        try {
            $this->story((string) $tenantId);
            $this->andesVacations((string) $tenantId);
        } finally {
            if ($wasUnguarded) {
                Model::unguard();
            }
        }
    }

    private function story(string $tenantId): void
    {
        TenancyScope::runAs($tenantId, function (): void {
            if (! DB::table('employments')->exists() || VacationRequest::query()->exists()) {
                return;
            }
            $admin = (string) DB::table('users')->orderBy('created_at')->value('id');
            // Una organización demo sembrada con una versión anterior puede no tener a todos: lo que falta se omite.
            $by = fn (string $email): ?Employment => Employment::query()->where('work_email', $email.'@demo.gestiahr.test')->first();

            // Saldos al día (lo mismo que hace la tarea diaria vacations:accrue).
            foreach (DB::table('employments')->whereIn('status', ['active', 'suspended'])->pluck('id') as $id) {
                app(VacationAccrual::class)->sync((string) $id);
            }
            $vacations = app(VacationRequestService::class);
            // Vacaciones ya gozadas, registradas por RRHH (acordadas fuera del sistema).
            // Ya gozadas (registradas por RRHH) y una aprobada para las próximas semanas (Transportes Inti, pequeña empresa).
            foreach ([['wilmer.ccori', -120, -106], ['carmen.delgado', -60, -46], ['nancy.condori', 21, 30]] as [$who, $from, $to]) {
                $e = $by($who);
                if ($e !== null) {
                    $vacations->register($vacations->create(['employment_id' => $e->id, 'starts_on' => DemoDates::inDays($from), 'ends_on' => DemoDates::inDays($to),
                        'exception_reason' => $from < 0 ? 'Vacaciones acordadas antes de usar GestiaHR.' : null], $admin), $admin);
                }
            }
            // En aprobación: la bandeja de la jefa de operaciones y de RRHH tiene un pendiente.
            $pendingFor = $by('edwin.huanca');
            if ($pendingFor !== null) {
                $pending = $vacations->create(['employment_id' => $pendingFor->id, 'starts_on' => DemoDates::inDays(35), 'ends_on' => DemoDates::inDays(44),
                    'notes' => 'Viaje familiar a Arequipa.'], $admin);
                $vacations->submit($pending, 'hr', $admin);
            }

            // Licencia sin goce en curso: las reglas del tipo rigen desde que se aplicó el paquete (un descanso médico exige sustento adjunto).
            $leaveType = DB::table('absence_types')->where('code', 'licencia_sin_goce')->value('id');
            $edwin = $by('edwin.huanca');
            if ($leaveType !== null && $edwin !== null) {
                $absences = app(AbsenceRequestService::class);
                $absence = $absences->create(['employment_id' => $edwin->id, 'absence_type_id' => (string) $leaveType,
                    'starts_on' => DemoDates::inDays(0), 'ends_on' => DemoDates::inDays(2), 'reason' => 'Trámite de documentos en Huancayo.'], $admin);
                $absences->register($absence, $admin);
            }

            // Promoción programada a la plaza libre de Especialista de RRHH.
            $target = DB::table('positions')->where('code', 'P-SRH')->first(['id', 'job_profile_id']);
            $diego = $by('diego.rojas');
            if ($target !== null && $diego !== null) {
                app(PersonnelActions::class)->register($diego, [
                    'effective_on' => now()->startOfMonth()->addMonth()->toDateString(),
                    'reason' => 'Promoción por desempeño en la implementación del legajo digital.',
                    'changes' => ['position_id' => (string) $target->id, 'job_profile_id' => (string) $target->job_profile_id],
                ], $admin, canCreate: true, canCorrect: false);
            }

            // Cese por renuncia el mes pasado: offboarding y plaza libre en almacén.
            app(TerminationReasonCatalog::class)->ensureCountryReasons();
            $resignation = DB::table('termination_reasons')->where('category', 'resignation')->whereNull('deleted_at')->orderBy('code')->value('id');
            $kevin = $by('kevin.paucar');
            if ($resignation !== null && $kevin !== null) {
                app(TerminationService::class)->register($kevin, [
                    'termination_reason_id' => (string) $resignation,
                    'termination_date' => DemoDates::daysAgo(20),
                    'notice_date' => DemoDates::daysAgo(50),
                    'notes' => 'Renuncia para continuar estudios.',
                ], $admin);
            }
        });
    }

    /**
     * Comercial Los Andes (régimen general, 30 días con el parámetro legal aprobado el 2026-11-20): dos solicitudes en
     * aprobación y el rol de vacaciones del año siguiente con una propuesta. Idempotente: solo agrega lo que falta,
     * también en una demo sembrada con una versión anterior.
     */
    private function andesVacations(string $tenantId): void
    {
        TenancyScope::runAs($tenantId, function (): void {
            $andes = DB::table('legal_entities')->where('tax_id', '20999999901')->value('id');
            if ($andes === null) {
                return;
            }
            $admin = (string) DB::table('users')->orderBy('created_at')->value('id');
            $by = fn (string $email): ?Employment => Employment::query()->where('work_email', $email.'@demo.gestiahr.test')->first();
            $vacations = app(VacationRequestService::class);
            foreach ([['valeria.ramos', 28, 37, 'Viaje a Cusco con la familia.'], ['raul.valdivia', 50, 56, 'Descanso después del cierre contable.']] as [$who, $from, $to, $note]) {
                $e = $by($who);
                if ($e === null || VacationRequest::query()->where('employment_id', $e->id)->exists()) {
                    continue;
                }
                app(VacationAccrual::class)->sync((string) $e->id);
                try {
                    $vacations->submit($vacations->create(['employment_id' => $e->id, 'starts_on' => DemoDates::inDays($from), 'ends_on' => DemoDates::inDays($to), 'notes' => $note], $admin), 'hr', $admin);
                } catch (\Throwable) {
                    // Sin saldo suficiente (organización demo antigua): se omite.
                }
            }
            // VAC-5 · Rol de vacaciones del año siguiente con una propuesta para la gerencia de Administración.
            $year = (int) now()->addYear()->format('Y');
            $patricia = $by('patricia.medina');
            if ($patricia !== null && ! VacationSchedule::query()->where('legal_entity_id', $andes)->where('year', $year)->exists()) {
                $scheduling = app(VacationScheduling::class);
                $schedule = $scheduling->open((string) $andes, $year, null, $admin);
                $scheduling->propose($schedule, (string) $patricia->id, $year.'-02-02', $year.'-02-16', $admin);
            }
        });
    }
}
