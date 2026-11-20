<?php

namespace App\Modules\Platform\Application\Settings;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\InvalidTransitionException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SAS-13 · Avisos de la plataforma (mantenimiento, incidencias) con vigencia, para todas las organizaciones o
 * solo las seleccionadas. Cancelar un aviso lo retira; no se borra.
 */
final class PlatformNotices
{
    public function __construct(private readonly ConsoleAudit $audit) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return DB::table('platform_notices')->orderByDesc('starts_at')->limit(200)->get()
            ->map(fn ($r): array => $this->present($r))->values()->all();
    }

    /**
     * Avisos vigentes para una organización (lo que ve su interfaz).
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeFor(string $tenantId): array
    {
        return DB::table('platform_notices')->whereNull('cancelled_at')
            ->where('starts_at', '<=', now())->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($q) => $q->where('audience', 'all')->orWhereRaw('tenant_ids @> ?::jsonb', [json_encode([$tenantId])]))
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->orderBy('starts_at')
            ->get()->map(fn ($r): array => [
                'id' => (string) $r->id, 'title' => (string) $r->title, 'message' => (string) $r->message,
                'severity' => (string) $r->severity,
                'starts_at' => Carbon::parse((string) $r->starts_at)->toIso8601String(),
                'ends_at' => $r->ends_at === null ? null : Carbon::parse((string) $r->ends_at)->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, PlatformUser $actor): array
    {
        $data = $this->normalize($input, null);
        $id = (string) Str::uuid();
        DB::table('platform_notices')->insert($data + ['id' => $id, 'created_by_platform_user_id' => $actor->id]);
        $row = $this->find($id);
        $this->audit->record('notice.created', $actor, 'platform_notice', $id, null, $this->present($row));

        return $this->present($row);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, PlatformUser $actor): array
    {
        $row = $this->find($id);
        if ($row->cancelled_at !== null) {
            throw new AyniException('notice_cancelled', 409, (string) trans('platform.notices.cancelled'));
        }
        $before = $this->present($row);
        DB::table('platform_notices')->where('id', $id)->update($this->normalize($input, $row));
        $after = $this->present($this->find($id));
        $this->audit->record('notice.updated', $actor, 'platform_notice', $id, $before, $after);

        return $after;
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $id, PlatformUser $actor): array
    {
        $row = $this->find($id);
        if ($row->cancelled_at !== null) {
            throw new InvalidTransitionException('cancelled');
        }
        DB::table('platform_notices')->where('id', $id)->update(['cancelled_at' => now()]);
        $this->audit->record('notice.cancelled', $actor, 'platform_notice', $id, ['status' => $this->status($row)], ['status' => 'cancelled']);

        return $this->present($this->find($id));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input, ?\stdClass $current): array
    {
        $audience = (string) ($input['audience'] ?? $current->audience ?? 'all');
        $tenantIds = array_key_exists('tenant_ids', $input) ? array_values(array_unique(array_map('strval', (array) $input['tenant_ids'])))
            : ($current?->tenant_ids === null ? [] : (array) json_decode((string) $current->tenant_ids, true));
        if ($audience === 'selected') {
            $valid = array_filter($tenantIds, fn (string $t): bool => TenancyScope::isValidUuid($t));
            if ($tenantIds === [] || count($valid) !== count($tenantIds)
                || DB::table('tenants')->whereIn('id', $tenantIds)->whereNull('deleted_at')->count() !== count($tenantIds)) {
                throw ValidationException::withMessages(['tenant_ids' => [(string) trans('platform.notices.tenants_required')]]);
            }
        }
        $starts = array_key_exists('starts_at', $input) ? Carbon::parse((string) $input['starts_at']) : ($current === null ? now() : Carbon::parse((string) $current->starts_at));
        $ends = array_key_exists('ends_at', $input) ? ($input['ends_at'] === null ? null : Carbon::parse((string) $input['ends_at'])) : ($current?->ends_at === null ? null : Carbon::parse((string) $current->ends_at));
        if ($ends !== null && $ends->lte($starts)) {
            throw ValidationException::withMessages(['ends_at' => [(string) trans('platform.notices.ends_before_starts')]]);
        }

        $data = [
            'audience' => $audience,
            'tenant_ids' => $audience === 'selected' ? json_encode($tenantIds, JSON_THROW_ON_ERROR) : null,
            'starts_at' => $starts,
            'ends_at' => $ends,
        ];
        foreach (['title', 'message', 'severity'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }

        return $data;
    }

    private function find(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('platform_notices')->where('id', $id)->first() : null;
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404);
        }

        return $row;
    }

    private function status(\stdClass $r): string
    {
        return match (true) {
            $r->cancelled_at !== null => 'cancelled',
            Carbon::parse((string) $r->starts_at)->isFuture() => 'scheduled',
            $r->ends_at !== null && Carbon::parse((string) $r->ends_at)->isPast() => 'ended',
            default => 'active',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $r): array
    {
        return [
            'id' => (string) $r->id,
            'title' => (string) $r->title,
            'message' => (string) $r->message,
            'severity' => (string) $r->severity,
            'audience' => (string) $r->audience,
            'tenant_ids' => $r->tenant_ids === null ? [] : array_values((array) json_decode((string) $r->tenant_ids, true)),
            'starts_at' => Carbon::parse((string) $r->starts_at)->toIso8601String(),
            'ends_at' => $r->ends_at === null ? null : Carbon::parse((string) $r->ends_at)->toIso8601String(),
            'status' => $this->status($r),
            'allowed_actions' => $r->cancelled_at === null ? ['cancel'] : [],
        ];
    }
}
