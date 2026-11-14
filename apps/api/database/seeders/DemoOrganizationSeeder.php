<?php

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Workflow\Application\ApprovalFlowService;
use App\Modules\Workflow\Application\TaskService;
use App\Modules\Workflow\Infrastructure\Models\ApprovalFlow;
use App\Modules\Workflow\Infrastructure\Models\Task;
use App\Shared\Security\BlindIndex;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Organización demo para desarrollo: se entra por http://demo.localhost:3000.
 *
 * La clave del administrador no está en el código: se toma de DEMO_ADMIN_PASSWORD
 * o se genera al azar y se muestra una sola vez en la consola.
 */
class DemoOrganizationSeeder extends Seeder
{
    public const SLUG = 'demo';

    public function run(): void
    {
        $tenantId = DB::table('tenants')->where('slug', self::SLUG)->value('id');
        if ($tenantId === null) {
            $tenantId = (string) Str::uuid();
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'slug' => self::SLUG,
                'name' => 'Organización Demo',
                'status' => 'active',
                'country_code' => 'PE',
                'default_currency' => 'PEN',
                'is_demo' => true,
            ]);
        }

        // Roles de sistema, sus permisos por defecto, política de seguridad y módulos base.
        DB::select('SELECT seed_tenant_defaults(?)', [$tenantId]);

        $email = (string) env('DEMO_ADMIN_EMAIL', 'admin@demo.gestiahr.test');
        $password = env('DEMO_ADMIN_PASSWORD');
        $generated = ! is_string($password) || $password === '';
        $password = $generated ? Str::password(16) : $password;

        $created = TenancyScope::runAs((string) $tenantId, function () use ($email, $password): bool {
            $adminRole = Role::query()->where('system_role_code', 'admin_empresa')->firstOrFail();

            /** @var UserIdentity|null $existing */
            $existing = UserIdentity::query()
                ->where('kind', 'email')
                ->where('identifier_hash', BlindIndex::for('email', $email))
                ->first();
            if ($existing !== null) {
                // Idempotente: un administrador sembrado antes de existir los roles recibe el suyo.
                if (! $existing->user->roleAssignments()->where('role_id', $adminRole->id)->exists()) {
                    $existing->user->roleAssignments()->create(['role_id' => $adminRole->id]);
                }

                return false;
            }

            $user = User::create([
                'display_name' => 'Administrador Demo',
                'status' => User::STATUS_ACTIVE,
                'locale' => 'es',
                'password_hash' => Hash::make($password),
                'password_changed_at' => now(),
            ]);
            $user->identities()->create(UserIdentity::attributesFor('email', $email) + ['is_primary' => true]);

            $user->roleAssignments()->create(['role_id' => $adminRole->id]);

            return true;
        });

        // Empresas del grupo demo (las mismas de la versión de demostración aprobada).
        TenancyScope::runAs((string) $tenantId, function (): void {
            $companies = [
                ['legal_name' => 'Comercial Los Andes S.A.C.', 'tax_id' => '20999999901', 'address' => 'Av. Nicolás Ayllón 4520, Ate, Lima', 'regime' => 'pe_general'],
                ['legal_name' => 'Transportes Inti S.A.C.', 'tax_id' => '20999999902', 'address' => 'Antigua Panamericana Sur km 32, Lurín, Lima', 'regime' => 'pe_pequena'],
            ];
            foreach ($companies as $c) {
                if (LegalEntity::query()->where('country_code', 'PE')->where('tax_id', $c['tax_id'])->exists()) {
                    continue;
                }
                $entity = LegalEntity::create([
                    'legal_name' => $c['legal_name'], 'tax_id' => $c['tax_id'], 'address' => $c['address'],
                    'country_code' => 'PE', 'currency' => 'PEN', 'timezone' => 'America/Lima', 'status' => 'active',
                ]);
                $entity->regimes()->create(['labor_regime_code' => $c['regime'], 'valid_from' => '2024-01-01']);
            }
        });

        // Flujo de vacaciones publicado y tareas de RRHH coherentes con las empresas demo (WFL-1, WFL-8).
        TenancyScope::runAs((string) $tenantId, function () use ($email): void {
            $flows = app(ApprovalFlowService::class);
            $rrhh = Role::query()->where('system_role_code', 'rrhh')->value('id');
            if ($rrhh !== null && ! ApprovalFlow::query()->where('subject_type', 'vacation_request')->exists()) {
                $flow = $flows->create(['name' => 'Vacaciones · aprobación estándar', 'subject_type' => 'vacation_request', 'steps' => ['levels' => [
                    ['name' => 'Jefe directo', 'mode' => 'sequential', 'sla_hours' => 48, 'approvers' => [['kind' => 'manager']]],
                    ['name' => 'Recursos Humanos', 'mode' => 'parallel_any', 'sla_hours' => 24, 'approvers' => [['kind' => 'role', 'role_id' => (string) $rrhh]]],
                ]], 'conditions' => []]);
                $flows->publish($flow, null);
            }
            // AUS-2: una ausencia solicitada la aprueba el jefe directo o Recursos Humanos (el primero que decida).
            if ($rrhh !== null && ! ApprovalFlow::query()->where('subject_type', 'absence_request')->exists()) {
                $flow = $flows->create(['name' => 'Ausencias · aprobación estándar', 'subject_type' => 'absence_request', 'steps' => ['levels' => [
                    ['name' => 'Jefe directo o Recursos Humanos', 'mode' => 'parallel_any', 'sla_hours' => 24, 'approvers' => [['kind' => 'manager'], ['kind' => 'role', 'role_id' => (string) $rrhh]]],
                ]], 'conditions' => []]);
                $flows->publish($flow, null);
            }
            // POS-3: sin flujo publicado no se puede enviar una solicitud de posición (RN-WFL-01).
            if ($rrhh !== null && ! ApprovalFlow::query()->where('subject_type', 'position_request')->exists()) {
                $flow = $flows->create(['name' => 'Nuevas posiciones · aprobación estándar', 'subject_type' => 'position_request', 'steps' => ['levels' => [
                    ['name' => 'Recursos Humanos', 'mode' => 'parallel_any', 'sla_hours' => 48, 'approvers' => [['kind' => 'role', 'role_id' => (string) $rrhh]]],
                ]], 'conditions' => []]);
                $flows->publish($flow, null);
            }
            // HIS-3: un cambio de sueldo espera la aprobación del administrador de la empresa.
            $companyAdmin = Role::query()->where('system_role_code', 'admin_empresa')->value('id');
            if ($companyAdmin !== null && ! ApprovalFlow::query()->where('subject_type', 'salary_change')->exists()) {
                $flow = $flows->create(['name' => 'Cambios de sueldo · aprobación estándar', 'subject_type' => 'salary_change', 'steps' => ['levels' => [
                    ['name' => 'Administración de la empresa', 'mode' => 'parallel_any', 'sla_hours' => 48, 'approvers' => [['kind' => 'role', 'role_id' => (string) $companyAdmin]]],
                ]], 'conditions' => []]);
                $flows->publish($flow, null);
            }
            // HIS-2: los movimientos que pasan por aprobación (promociones y lo que propone un jefe) usan este flujo.
            if ($rrhh !== null && ! ApprovalFlow::query()->where('subject_type', 'personnel_action')->exists()) {
                $flow = $flows->create(['name' => 'Movimientos de personal · aprobación estándar', 'subject_type' => 'personnel_action', 'steps' => ['levels' => [
                    ['name' => 'Recursos Humanos', 'mode' => 'parallel_any', 'sla_hours' => 48, 'approvers' => [['kind' => 'role', 'role_id' => (string) $rrhh]]],
                ]], 'conditions' => []]);
                $flows->publish($flow, null);
            }

            $admin = UserIdentity::query()->where('kind', 'email')->where('identifier_hash', BlindIndex::for('email', $email))->value('user_id');
            if ($admin === null || Task::query()->exists()) {
                return;
            }
            $tasks = app(TaskService::class);
            foreach ([
                ['title' => 'Revisar el régimen laboral de Transportes Inti S.A.C.', 'description' => 'Confirmar que sigue en el régimen de pequeña empresa antes del próximo cierre.', 'assignee_user_id' => $admin, 'priority' => 'high', 'due_on' => now()->addDays(3)->toDateString()],
                ['title' => 'Definir quién aprueba los gastos de viaje', 'description' => 'Crear el flujo de aprobación de gastos en Configuración → Flujos de aprobación.', 'assignee_user_id' => $admin, 'priority' => 'normal', 'due_on' => now()->subDay()->toDateString()],
                ['title' => 'Cargar el organigrama de Comercial Los Andes S.A.C.', 'assignee_role_id' => $rrhh, 'priority' => 'normal', 'due_on' => now()->addWeek()->toDateString()],
            ] as $task) {
                $tasks->create($task, (string) $admin);
            }
        });

        if ($created && $this->command !== null) {
            $this->command->info("Organización demo: http://demo.localhost:3000 · usuario {$email}");
            if ($generated) {
                $this->command->warn("Clave generada (se muestra una sola vez): {$password}");
            }
        }
    }
}
