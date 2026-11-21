<?php

namespace App\Modules\Payroll\Console;

use App\Modules\Payroll\Application\PayrollObligations;
use App\Shared\Notifications\Notifier;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CUM-9 · Aviso diario de obligaciones de planilla (NOT-2): una vez antes del vencimiento (cumplimiento.aviso_dias)
 * y una vez al vencer sin cumplirse. Al responsable asignado o, si no hay, a quienes calculan la planilla. La
 * tabla payroll_obligation_alerts (solo inserción, clave única) evita repetir el aviso. Organización por organización.
 */
final class NotifyPayrollObligations extends Command
{
    protected $signature = 'payroll:obligation-alerts {--date= : Fecha a evaluar (AAAA-MM-DD); por defecto hoy}';

    protected $description = 'Avisos de vencimiento de obligaciones de planilla (CUM-9)';

    public function handle(TenantDirectory $tenants, PayrollObligations $obligations, Notifier $notifier): int
    {
        $option = $this->option('date');
        $date = is_string($option) && $option !== '' ? $option : now()->toDateString();
        $sent = 0;
        foreach ($tenants->operableTenantIds() as $tenantId) {
            $sent += TenancyScope::runAs($tenantId, fn (): int => $this->sendAlerts($obligations, $notifier, $date));
        }
        $this->info((string) trans('payroll.obligations.alerts_sent', ['count' => $sent]));

        return self::SUCCESS;
    }

    private function sendAlerts(PayrollObligations $obligations, Notifier $notifier, string $date): int
    {
        $sent = 0;
        foreach (DB::table('legal_entities')->whereNull('deleted_at')->get(['id', 'legal_name']) as $entity) {
            foreach ($obligations->board((string) $entity->id, $date) as $item) {
                $kind = match ($item['status']) {
                    'due_soon' => 'before_due',
                    'overdue' => 'overdue',
                    default => null,
                };
                if ($kind === null || $item['due_on'] === null) {
                    continue;
                }
                $inserted = DB::table('payroll_obligation_alerts')->insertOrIgnore([
                    'tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => (string) $entity->id, 'obligation_code' => $item['code'],
                    'period_key' => (string) $item['period_key'], 'kind' => $kind,
                ]);
                if ($inserted === 0) {
                    continue;
                }
                $users = $item['responsible'] !== null ? [(string) $item['responsible']['user_id']] : $this->payrollUsers($date);
                $sent += $notifier->notify($kind === 'overdue' ? 'payroll.obligation_overdue' : 'payroll.obligation_due', $users,
                    ['obligation' => (string) $item['name'], 'company' => (string) $entity->legal_name, 'due_on' => (string) $item['due_on']],
                    'legal_entity', (string) $entity->id) > 0 ? 1 : 0;
            }
        }

        return $sent;
    }

    /** @return list<string> */
    private function payrollUsers(string $today): array
    {
        return array_values(DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')->whereNull('r.deleted_at')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('rp.permission_code', 'payroll.periods.calculate')
            ->where('u.status', 'active')->whereNull('u.deleted_at')
            ->where(fn ($q) => $q->whereNull('ur.valid_from')->orWhere('ur.valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ur.valid_to')->orWhere('ur.valid_to', '>=', $today))
            ->distinct()->pluck('u.id')->map(fn ($id): string => (string) $id)->all());
    }
}
