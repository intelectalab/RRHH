<?php

namespace App\Modules\Platform\Application\Monitoring;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Tenancy\CrossTenantMaintenance;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Usage\UsageMetrics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * SAS-9 · Salud de la plataforma: estado de componentes, colas, trabajos y eventos fallidos, webhooks,
 * almacenamiento, integraciones y uso de la API por organización. Solo cifras y metadatos técnicos: nunca
 * el contenido de los eventos ni datos de RRHH (RN-SAS-12).
 */
final class PlatformHealth
{
    public function __construct(
        private readonly CrossTenantMaintenance $cross,
        private readonly IntegrationManager $integrations,
        private readonly ConsoleAudit $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(?string $tenantId): array
    {
        $month = now()->startOfMonth()->toDateString();
        $perTenant = $this->cross->readAcrossTenants(function () use ($tenantId, $month): array {
            $outbox = DB::table('outbox_events')->whereIn('status', ['failed', 'dead'])
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->selectRaw('tenant_id, count(*) AS n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
            $webhooks = DB::table('webhook_deliveries')->whereIn('status', ['failed', 'dead'])
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->selectRaw('tenant_id, count(*) AS n')->groupBy('tenant_id')->pluck('n', 'tenant_id');
            $api = DB::table('tenant_usage_counters')->where('metric_code', UsageMetrics::API_CALLS)->where('period_start', $month)
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                ->pluck('value', 'tenant_id');
            $storage = DB::table('tenant_usage_counters AS c')->where('c.metric_code', UsageMetrics::STORAGE_MB)
                ->whereRaw('c.period_start = (SELECT max(period_start) FROM tenant_usage_counters x WHERE x.tenant_id = c.tenant_id AND x.metric_code = c.metric_code)')
                ->when($tenantId !== null, fn ($q) => $q->where('c.tenant_id', $tenantId))
                ->pluck('c.value', 'c.tenant_id');

            return ['outbox' => $outbox->all(), 'webhooks' => $webhooks->all(), 'api' => $api->all(), 'storage' => $storage->all()];
        });
        $failedJobs = $this->failedJobRows($tenantId);
        $jobsByTenant = [];
        foreach ($failedJobs as $job) {
            $key = $job['tenant_id'] ?? '';
            $jobsByTenant[$key] = ($jobsByTenant[$key] ?? 0) + 1;
        }

        $ids = array_unique(array_merge(array_keys($perTenant['outbox']), array_keys($perTenant['webhooks']), array_keys($perTenant['api']),
            array_keys($perTenant['storage']), array_filter(array_keys($jobsByTenant), fn ($k): bool => $k !== '')));
        $names = DB::table('tenants')->whereIn('id', $ids === [] ? ['00000000-0000-0000-0000-000000000000'] : $ids)->pluck('name', 'id');
        $tenants = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            $tenants[] = [
                'tenant_id' => $id,
                'name' => (string) ($names[$id] ?? ''),
                'api_calls_month' => (int) ($perTenant['api'][$id] ?? 0),
                'storage_mb' => (float) ($perTenant['storage'][$id] ?? 0),
                'errors' => [
                    'failed_jobs' => $jobsByTenant[$id] ?? 0,
                    'outbox_events' => (int) ($perTenant['outbox'][$id] ?? 0),
                    'webhook_deliveries' => (int) ($perTenant['webhooks'][$id] ?? 0),
                ],
            ];
        }
        usort($tenants, fn (array $a, array $b): int => [array_sum($b['errors']), $b['api_calls_month']] <=> [array_sum($a['errors']), $a['api_calls_month']]);

        $components = $this->components();

        return [
            'status' => in_array('down', array_column($components, 'status'), true) ? 'down'
                : (in_array('degraded', array_column($components, 'status'), true) ? 'degraded' : 'operational'),
            'components' => $components,
            'queues' => $this->queues(),
            'failed_jobs' => count($failedJobs),
            'outbox' => ['failed' => array_sum(array_map('intval', $perTenant['outbox']))],
            'webhooks' => ['failed' => array_sum(array_map('intval', $perTenant['webhooks']))],
            'integrations' => $this->integrations->status(),
            'tenants' => array_slice($tenants, 0, 100),
            'incidents' => DB::table('platform_incidents')->where('status', '!=', 'resolved')->orderByDesc('started_at')->limit(20)
                ->get(['id', 'title', 'severity', 'status', 'started_at'])->map(fn ($i): array => [
                    'id' => (string) $i->id, 'title' => (string) $i->title, 'severity' => (string) $i->severity,
                    'status' => (string) $i->status, 'started_at' => Carbon::parse((string) $i->started_at)->toIso8601String(),
                ])->values()->all(),
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function failedJobs(?string $tenantId, int $page, int $perPage): array
    {
        $rows = $this->failedJobRows($tenantId);

        return ['data' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => count($rows)]];
    }

    public function retryFailedJob(string $id, PlatformUser $actor): void
    {
        // La tabla de trabajos fallidos la crea la migración de infraestructura de Laravel; sin ella no hay nada que reintentar.
        $job = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('uuid', $id)->first(['uuid', 'queue', 'payload']) : null;
        if ($job === null) {
            throw new AyniException('not_found', 404);
        }
        $tenantId = $this->tenantOf((string) $job->payload);
        if (Artisan::call('queue:retry', ['id' => [$id]]) !== 0) {
            throw new AyniException('retry_failed', 409, (string) trans('platform.health.retry_failed'));
        }
        $this->audit->record('health.job_retried', $actor, 'failed_job', null, null, ['job' => $id, 'queue' => (string) $job->queue], $tenantId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function outboxEvents(?string $tenantId, ?string $status): array
    {
        $statuses = $status !== null && in_array($status, ['failed', 'dead'], true) ? [$status] : ['failed', 'dead'];
        $rows = $this->cross->readAcrossTenants(fn () => DB::table('outbox_events')->whereIn('status', $statuses)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('occurred_at')->limit(200)
            ->get(['id', 'tenant_id', 'event_type', 'status', 'attempts', 'last_error', 'occurred_at']));
        $names = DB::table('tenants')->whereIn('id', $rows->pluck('tenant_id')->unique()->all())->pluck('name', 'id');

        return $rows->map(fn ($r): array => [
            'id' => (string) $r->id,
            'tenant_id' => (string) $r->tenant_id,
            'tenant_name' => (string) ($names[$r->tenant_id] ?? ''),
            'event_type' => (string) $r->event_type,
            'status' => (string) $r->status,
            'attempts' => (int) $r->attempts,
            'error' => self::firstLine((string) $r->last_error),
            'occurred_at' => Carbon::parse((string) $r->occurred_at)->toIso8601String(),
        ])->values()->all();
    }

    public function retryOutboxEvent(string $id, PlatformUser $actor): void
    {
        if (! TenancyScope::isValidUuid($id)) {
            throw new AyniException('not_found', 404);
        }
        $row = $this->cross->readAcrossTenants(fn () => DB::table('outbox_events')->where('id', $id)->first(['id', 'tenant_id', 'status', 'event_type']));
        if ($row === null) {
            throw new AyniException('not_found', 404);
        }
        if (! in_array((string) $row->status, ['failed', 'dead'], true)) {
            throw new InvalidTransitionException((string) $row->status);
        }
        TenancyScope::runAs((string) $row->tenant_id, fn () => DB::table('outbox_events')->where('id', $id)
            ->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'last_error' => null]));
        $this->audit->record('health.event_retried', $actor, 'outbox_event', $id, ['status' => (string) $row->status], ['status' => 'pending', 'event_type' => (string) $row->event_type], (string) $row->tenant_id);
    }

    /**
     * Página de estado: base de datos, caché y colas (Redis), cola de trabajos, copias y correo.
     *
     * @return list<array{code: string, status: string, detail: string|null}>
     */
    private function components(): array
    {
        $out = [];
        $out[] = $this->probe('database', fn () => DB::select('SELECT 1'));
        $out[] = $this->probe('redis', fn () => Redis::connection()->ping(), 'degraded');
        $out[] = $this->probe('queue', fn () => Queue::size(), 'degraded');
        $backup = DB::table('backup_runs')->where('kind', 'automatic')->orderByDesc('started_at')->first(['status', 'started_at']);
        $out[] = ['code' => 'backups', 'status' => $backup === null ? 'unknown' : ((string) $backup->status === 'failed' ? 'degraded' : 'operational'),
            'detail' => $backup === null ? null : Carbon::parse((string) $backup->started_at)->toIso8601String()];
        foreach ($this->integrations->status() as $port) {
            if (in_array($port['port'], ['email', 'messaging', 'backups'], true)) {
                $out[] = ['code' => 'integration.'.$port['port'], 'status' => $port['provider'] === null ? 'down' : 'operational', 'detail' => $port['provider']];
            }
        }

        return $out;
    }

    /**
     * @return array{code: string, status: string, detail: string|null}
     */
    private function probe(string $code, callable $check, string $onFailure = 'down'): array
    {
        try {
            $check();

            return ['code' => $code, 'status' => 'operational', 'detail' => null];
        } catch (Throwable $e) {
            return ['code' => $code, 'status' => $onFailure, 'detail' => self::firstLine($e->getMessage())];
        }
    }

    /**
     * @return list<array{name: string, size: int|null}>
     */
    private function queues(): array
    {
        $out = [];
        foreach (array_unique(['default', ...(array) config('platform.monitored_queues', [])]) as $name) {
            try {
                $out[] = ['name' => (string) $name, 'size' => Queue::size((string) $name)];
            } catch (Throwable) {
                $out[] = ['name' => (string) $name, 'size' => null];
            }
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function failedJobRows(?string $tenantId): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }
        $rows = DB::table('failed_jobs')->orderByDesc('failed_at')->limit(1000)->get(['uuid', 'queue', 'payload', 'exception', 'failed_at']);
        $out = [];
        foreach ($rows as $r) {
            $tenant = $this->tenantOf((string) $r->payload);
            if ($tenantId !== null && $tenant !== $tenantId) {
                continue;
            }
            $payload = json_decode((string) $r->payload, true);
            $out[] = [
                'id' => (string) $r->uuid,
                'queue' => (string) $r->queue,
                'job' => is_array($payload) ? (string) ($payload['displayName'] ?? '') : '',
                'tenant_id' => $tenant,
                'error' => self::firstLine((string) $r->exception),
                'failed_at' => Carbon::parse((string) $r->failed_at)->toIso8601String(),
            ];
        }

        return $out;
    }

    private function tenantOf(string $payload): ?string
    {
        $data = json_decode($payload, true);
        $id = is_array($data) ? ($data['tenant_id'] ?? null) : null;

        return is_string($id) && TenancyScope::isValidUuid($id) ? $id : null;
    }

    private static function firstLine(string $text): ?string
    {
        $line = trim(strtok($text, "\n") ?: '');

        return $line === '' ? null : mb_substr($line, 0, 300);
    }
}
