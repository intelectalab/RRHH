<?php

namespace App\Modules\Platform\Application\Backups;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Application\PlatformSettings;
use App\Modules\Platform\Domain\BackupRestoreStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * SAS-12 · Copias de seguridad y restauración segura (RN-SAS-09).
 *
 * - Copias automáticas (diarias) y manuales, de toda la plataforma o de una organización (su exportación completa),
 *   con resultado, tamaño, suma de verificación y retención (backups.retention_days).
 * - Restaurar: se pide con motivo; la aprueba otro usuario de la consola (doble confirmación) y recién entonces se
 *   ejecuta. Se restaura en aislado («sandbox») y se verifica; a producción solo si esa misma copia ya tiene una
 *   restauración aislada verificada. Nunca se sobrescribe producción sin ese paso.
 * - Cada backups.test_restore_every_days se prueba sola una restauración aislada de la última copia y queda la evidencia.
 * - El almacenamiento y el entorno aislado son del proveedor, por el puerto BackupStoragePort.
 */
final class Backups
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly ConsoleAudit $audit,
        private readonly PlatformSettings $settings,
        private readonly BackupRestoreStateMachine $machine,
    ) {}

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function list(?string $tenantId, ?string $kind, ?string $status, int $page, int $perPage): array
    {
        $query = DB::table('backup_runs')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($status !== null, fn ($q) => $q->where('status', $status));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('started_at')->forPage($page, $perPage)->get();

        return ['data' => $this->presentRuns($rows->all()), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $run = $this->findRun($id);
        $restores = DB::table('backup_restores')->where('backup_run_id', $id)->orderByDesc('created_at')->get();

        return $this->presentRuns([$run])[0] + ['restores' => $this->presentRestores($restores->all())];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $kind, string $coverage, ?string $tenantId, ?PlatformUser $actor): array
    {
        if ($coverage === 'tenant' && ($tenantId === null || ! DB::table('tenants')->where('id', $tenantId)->whereNull('deleted_at')->exists())) {
            throw ValidationException::withMessages(['tenant_id' => [(string) trans('platform.backups.tenant_required')]]);
        }
        if ($coverage === 'platform') {
            $tenantId = null;
        }
        if (DB::table('backup_runs')->where('status', 'running')->where('coverage', $coverage)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId), fn ($q) => $q->whereNull('tenant_id'))->exists()) {
            throw new AyniException('backup_running', 409, (string) trans('platform.backups.already_running'));
        }

        $id = (string) Str::uuid();
        DB::table('backup_runs')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'kind' => $kind, 'coverage' => $coverage, 'status' => 'running',
            'started_at' => now(), 'requested_by_platform_user_id' => $actor?->id,
        ]);
        try {
            $result = $this->integrations->backups()->create($coverage, $tenantId);
            DB::table('backup_runs')->where('id', $id)->update([
                'status' => 'completed', 'storage_key' => $result['storage_key'], 'size_bytes' => $result['size_bytes'],
                'checksum' => $result['checksum'], 'finished_at' => now(),
                'retention_until' => now()->addDays($this->retentionDays())->toDateString(),
            ]);
        } catch (Throwable $e) {
            DB::table('backup_runs')->where('id', $id)->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
        $row = $this->findRun($id);
        $this->audit->record('backup.'.$kind, $actor, 'backup_run', $id, null, [
            'coverage' => $coverage, 'status' => (string) $row->status, 'size_bytes' => $row->size_bytes,
        ], $tenantId);

        return $this->show($id);
    }

    /**
     * @param  array{target: string, reason: string}  $input
     * @return array<string, mixed>
     */
    public function requestRestore(string $backupId, array $input, PlatformUser $actor): array
    {
        $run = $this->findRun($backupId);
        if ((string) $run->status !== 'completed') {
            throw new AyniException('backup_not_restorable', 409, (string) trans('platform.backups.not_completed'));
        }
        if ($input['target'] === 'production' && ! $this->verifiedInSandbox($backupId)) {
            throw new AyniException('sandbox_verification_required', 409, (string) trans('platform.backups.sandbox_required'));
        }
        $id = (string) Str::uuid();
        DB::table('backup_restores')->insert([
            'id' => $id, 'backup_run_id' => $backupId, 'tenant_id' => $run->tenant_id, 'status' => 'requested',
            'target' => $input['target'], 'kind' => 'manual', 'requested_by_platform_user_id' => $actor->id, 'reason' => $input['reason'],
        ]);
        $this->audit->record('backup.restore_requested', $actor, 'backup_restore', $id, null, [
            'backup_run_id' => $backupId, 'target' => $input['target'], 'reason' => $input['reason'],
        ], $run->tenant_id === null ? null : (string) $run->tenant_id);

        return $this->restore($id);
    }

    /**
     * Segunda confirmación: otro usuario de la consola la aprueba y la restauración se ejecuta y verifica.
     *
     * @return array<string, mixed>
     */
    public function approve(string $restoreId, PlatformUser $actor): array
    {
        $row = $this->findRestore($restoreId);
        $this->machine->getNextState((string) $row->status, BackupRestoreStateMachine::APPROVE);
        if ($row->requested_by_platform_user_id !== null && (string) $row->requested_by_platform_user_id === (string) $actor->id) {
            throw new AyniException('same_approver', 409, (string) trans('platform.backups.same_approver'));
        }
        if ((string) $row->target === 'production' && ! $this->verifiedInSandbox((string) $row->backup_run_id)) {
            throw new AyniException('sandbox_verification_required', 409, (string) trans('platform.backups.sandbox_required'));
        }
        DB::table('backup_restores')->where('id', $restoreId)->update(['status' => 'approved', 'approved_by_platform_user_id' => $actor->id, 'approved_at' => now()]);
        $this->audit->record('backup.restore_approved', $actor, 'backup_restore', $restoreId, ['status' => 'requested'], ['status' => 'approved'], $row->tenant_id === null ? null : (string) $row->tenant_id);

        $this->execute($restoreId, $actor);

        return $this->restore($restoreId);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $restoreId, PlatformUser $actor, ?string $reason): array
    {
        $row = $this->findRestore($restoreId);
        $next = $this->machine->getNextState((string) $row->status, BackupRestoreStateMachine::CANCEL);
        DB::table('backup_restores')->where('id', $restoreId)->update(['status' => $next, 'finished_at' => now()]);
        $this->audit->record('backup.restore_cancelled', $actor, 'backup_restore', $restoreId, ['status' => (string) $row->status], ['status' => $next, 'reason' => $reason], $row->tenant_id === null ? null : (string) $row->tenant_id);

        return $this->restore($restoreId);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function restores(?string $status, int $page, int $perPage): array
    {
        $query = DB::table('backup_restores')->when($status !== null, fn ($q) => $q->where('status', $status));
        $total = (clone $query)->count();

        return ['data' => $this->presentRestores($query->orderByDesc('created_at')->forPage($page, $perPage)->get()->all()),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    /**
     * Copia automática diaria de la plataforma y vencimiento de las copias pasadas de retención.
     *
     * @return array{run: string, expired: int}
     */
    public function runScheduled(): array
    {
        $run = $this->run('automatic', 'platform', null, null);
        $expired = 0;
        $old = DB::table('backup_runs')->where('status', 'completed')->whereNotNull('retention_until')
            ->where('retention_until', '<', now()->toDateString())->get(['id', 'storage_key', 'tenant_id']);
        foreach ($old as $row) {
            if ($row->storage_key !== null) {
                $this->integrations->backups()->purge((string) $row->storage_key);
            }
            DB::table('backup_runs')->where('id', $row->id)->update(['status' => 'expired']);
            $this->audit->record('backup.expired', null, 'backup_run', (string) $row->id, ['status' => 'completed'], ['status' => 'expired'], $row->tenant_id === null ? null : (string) $row->tenant_id);
            $expired++;
        }

        return ['run' => (string) $run['status'], 'expired' => $expired];
    }

    /**
     * Restauración de prueba periódica en aislado de la última copia completa de la plataforma; deja evidencia.
     * Si la última prueba es más reciente que backups.test_restore_every_days, no hace nada.
     *
     * @return array<string, mixed>|null
     */
    public function scheduledTestRestore(bool $force = false): ?array
    {
        $every = max(1, (int) ($this->settings->value('backups.test_restore_every_days') ?? 7));
        $last = DB::table('backup_restores')->where('kind', 'scheduled_test')->max('created_at');
        if (! $force && $last !== null && Carbon::parse((string) $last)->gt(now()->subDays($every))) {
            return null;
        }
        $run = DB::table('backup_runs')->where('status', 'completed')->where('coverage', 'platform')->orderByDesc('started_at')->first(['id']);
        if ($run === null) {
            return null;
        }
        $id = (string) Str::uuid();
        DB::table('backup_restores')->insert([
            'id' => $id, 'backup_run_id' => $run->id, 'tenant_id' => null, 'status' => 'approved', 'target' => 'sandbox',
            'kind' => 'scheduled_test', 'reason' => (string) trans('platform.backups.scheduled_reason'), 'approved_at' => now(),
        ]);
        $this->execute($id, null);
        $this->audit->record('backup.test_restore', null, 'backup_restore', $id, null, ['status' => (string) $this->findRestore($id)->status], null);

        return $this->restore($id);
    }

    private function execute(string $restoreId, ?PlatformUser $actor): void
    {
        $row = $this->findRestore($restoreId);
        $run = $this->findRun((string) $row->backup_run_id);
        DB::table('backup_restores')->where('id', $restoreId)->update(['status' => $this->machine->getNextState((string) $row->status, BackupRestoreStateMachine::RUN), 'started_at' => now()]);
        try {
            $result = $this->integrations->backups()->restore((string) $run->storage_key, (string) $run->checksum, (string) $row->target);
            $status = $result['verified'] ? 'completed' : 'failed';
            DB::table('backup_restores')->where('id', $restoreId)->update([
                'status' => $status, 'finished_at' => now(), 'verification' => json_encode($result, JSON_THROW_ON_ERROR),
                'error' => $result['verified'] ? null : (string) trans('platform.backups.verification_failed'),
            ]);
        } catch (Throwable $e) {
            $status = 'failed';
            DB::table('backup_restores')->where('id', $restoreId)->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
        $this->audit->record('backup.restore_'.$status, $actor, 'backup_restore', $restoreId, ['status' => 'running'], ['status' => $status, 'target' => (string) $row->target], $row->tenant_id === null ? null : (string) $row->tenant_id);

        // La organización afectada queda avisada en su propia auditoría cuando se restaura en producción.
        if ($status === 'completed' && (string) $row->target === 'production' && $row->tenant_id !== null) {
            TenancyScope::runAs((string) $row->tenant_id, fn () => app(AuditLogger::class)->record('platform.backup_restored', 'backup_restore', $restoreId, null, [
                'backup_taken_at' => Carbon::parse((string) $run->started_at)->toIso8601String(),
            ], (string) $row->reason));
        }
    }

    private function verifiedInSandbox(string $backupId): bool
    {
        return DB::table('backup_restores')->where('backup_run_id', $backupId)->where('target', 'sandbox')->where('status', 'completed')->exists();
    }

    private function retentionDays(): int
    {
        return max(1, (int) ($this->settings->value('backups.retention_days') ?? 30));
    }

    /**
     * @return array<string, mixed>
     */
    private function restore(string $id): array
    {
        return $this->presentRestores([$this->findRestore($id)])[0];
    }

    private function findRun(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('backup_runs')->where('id', $id)->first() : null;
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404);
        }

        return $row;
    }

    private function findRestore(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('backup_restores')->where('id', $id)->first() : null;
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404);
        }

        return $row;
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function presentRuns(array $rows): array
    {
        $tenants = DB::table('tenants')->whereIn('id', array_values(array_filter(array_map(fn ($r) => $r->tenant_id, $rows))))->pluck('name', 'id');
        $users = $this->userNames(array_map(fn ($r) => $r->requested_by_platform_user_id, $rows));
        $verified = DB::table('backup_restores')->whereIn('backup_run_id', array_map(fn ($r) => $r->id, $rows))
            ->where('status', 'completed')->where('target', 'sandbox')->pluck('backup_run_id')->flip();

        return array_map(fn (\stdClass $r): array => [
            'id' => (string) $r->id,
            'kind' => (string) $r->kind,
            'coverage' => (string) $r->coverage,
            'tenant_id' => $r->tenant_id,
            'tenant_name' => $r->tenant_id === null ? null : ($tenants[$r->tenant_id] ?? null),
            'status' => (string) $r->status,
            'size_bytes' => $r->size_bytes === null ? null : (int) $r->size_bytes,
            'checksum' => $r->checksum,
            'started_at' => Carbon::parse((string) $r->started_at)->toIso8601String(),
            'finished_at' => $r->finished_at === null ? null : Carbon::parse((string) $r->finished_at)->toIso8601String(),
            'retention_until' => $r->retention_until,
            'requested_by' => $r->requested_by_platform_user_id === null ? null : ($users[$r->requested_by_platform_user_id] ?? null),
            'verified_in_sandbox' => isset($verified[$r->id]),
            'error' => $r->error,
        ], $rows);
    }

    /**
     * @param  array<int, \stdClass>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function presentRestores(array $rows): array
    {
        $users = $this->userNames(array_merge(array_map(fn ($r) => $r->requested_by_platform_user_id, $rows), array_map(fn ($r) => $r->approved_by_platform_user_id, $rows)));

        return array_map(function (\stdClass $r) use ($users): array {
            $verification = $r->verification === null ? null : json_decode((string) $r->verification, true);

            return [
                'id' => (string) $r->id,
                'backup_run_id' => (string) $r->backup_run_id,
                'tenant_id' => $r->tenant_id,
                'kind' => (string) $r->kind,
                'target' => (string) $r->target,
                'status' => (string) $r->status,
                'reason' => (string) $r->reason,
                'requested_by' => $r->requested_by_platform_user_id === null ? null : ($users[$r->requested_by_platform_user_id] ?? null),
                'approved_by' => $r->approved_by_platform_user_id === null ? null : ($users[$r->approved_by_platform_user_id] ?? null),
                'approved_at' => $r->approved_at === null ? null : Carbon::parse((string) $r->approved_at)->toIso8601String(),
                'verification' => is_array($verification) ? $verification : null,
                'started_at' => $r->started_at === null ? null : Carbon::parse((string) $r->started_at)->toIso8601String(),
                'finished_at' => $r->finished_at === null ? null : Carbon::parse((string) $r->finished_at)->toIso8601String(),
                'created_at' => Carbon::parse((string) $r->created_at)->toIso8601String(),
                'error' => $r->error,
                'allowed_actions' => $this->machine->allowedActions((string) $r->status) === [] ? [] : array_values(array_intersect(['approve', 'cancel'], $this->machine->allowedActions((string) $r->status))),
            ];
        }, $rows);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<string, string>
     */
    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($v) => is_string($v) ? $v : null, $ids))));
        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = DB::table('platform_users')->whereIn('id', $ids)->pluck('full_name', 'id')->map(fn ($n): string => (string) $n)->all();

        return $names;
    }
}
