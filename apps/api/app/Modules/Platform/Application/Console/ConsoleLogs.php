<?php

namespace App\Modules\Platform\Application\Console;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SAS-13 · Registros de la consola, por separado de la auditoría: la actividad de los usuarios de una organización
 * (qué pantalla o acción y cuándo, sin datos de RRHH) y los logs técnicos estructurados (ya depurados de datos
 * sensibles por SensitiveDataScrubber). Ambos se filtran por organización, usuario y fecha.
 */
final class ConsoleLogs
{
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function activity(string $tenantId, ?string $userId, ?string $from, ?string $to, int $page, int $perPage): array
    {
        return TenancyScope::runAs($tenantId, function () use ($userId, $from, $to, $page, $perPage): array {
            $query = DB::table('activity_events AS a')->leftJoin('users AS u', 'u.id', '=', 'a.user_id')
                ->when($userId !== null, fn ($q) => $q->where('a.user_id', $userId))
                ->when($from !== null, fn ($q) => $q->where('a.occurred_at', '>=', Carbon::parse((string) $from)->startOfDay()))
                ->when($to !== null, fn ($q) => $q->where('a.occurred_at', '<=', Carbon::parse((string) $to)->endOfDay()));
            $total = (clone $query)->count();
            $rows = $query->orderByDesc('a.occurred_at')->forPage($page, $perPage)
                ->get(['a.id', 'a.user_id', 'u.display_name', 'a.activity', 'a.subject_type', 'a.occurred_at']);

            return ['data' => $rows->map(fn ($r): array => [
                'id' => (string) $r->id,
                'user_id' => $r->user_id,
                'user_name' => $r->display_name,
                'activity' => (string) $r->activity,
                'subject_type' => $r->subject_type,
                'occurred_at' => Carbon::parse((string) $r->occurred_at)->toIso8601String(),
            ])->values()->all(), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
        });
    }

    /**
     * Últimas líneas del log técnico estructurado (JSON por línea), de la más reciente a la más antigua.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function technical(?string $tenantId, ?string $userId, ?string $level, ?string $from, ?string $to, int $limit): array
    {
        $path = (string) config('platform.technical_log_path');
        if ($path === '' || ! is_readable($path)) {
            return ['data' => [], 'meta' => ['available' => false, 'total' => 0]];
        }
        $min = $level === null ? 0 : (int) array_search($level, self::LEVELS, true);
        $fromAt = $from === null ? null : Carbon::parse($from)->startOfDay();
        $toAt = $to === null ? null : Carbon::parse($to)->endOfDay();

        $out = [];
        foreach (array_reverse($this->tail($path, 5000)) as $line) {
            $entry = json_decode($line, true);
            if (! is_array($entry)) {
                continue;
            }
            $extra = (array) ($entry['extra'] ?? []);
            $context = (array) ($entry['context'] ?? []);
            $entryTenant = $extra['tenant_id'] ?? $context['tenant_id'] ?? null;
            $entryUser = $extra['user_id'] ?? $context['user_id'] ?? null;
            $entryLevel = strtolower((string) ($entry['level_name'] ?? 'info'));
            $at = isset($entry['datetime']) ? Carbon::parse((string) $entry['datetime']) : null;
            if (($tenantId !== null && $entryTenant !== $tenantId) || ($userId !== null && $entryUser !== $userId)
                || (int) array_search($entryLevel, self::LEVELS, true) < $min
                || ($fromAt !== null && ($at === null || $at->lt($fromAt))) || ($toAt !== null && ($at === null || $at->gt($toAt)))) {
                continue;
            }
            $out[] = [
                'occurred_at' => $at?->toIso8601String(),
                'level' => $entryLevel,
                'message' => mb_substr((string) ($entry['message'] ?? ''), 0, 500),
                'tenant_id' => $entryTenant,
                'user_id' => $entryUser,
                'request_id' => $extra['request_id'] ?? null,
                'context' => $context,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return ['data' => $out, 'meta' => ['available' => true, 'total' => count($out)]];
    }

    /**
     * @return list<string>
     */
    private function tail(string $path, int $lines): array
    {
        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);
        $last = $file->key();
        $out = [];
        for ($i = max(0, $last - $lines); $i <= $last; $i++) {
            $file->seek($i);
            $current = $file->current();
            $line = is_string($current) ? trim($current) : '';
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }
}
