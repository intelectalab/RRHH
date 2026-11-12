<?php

namespace App\Modules\Platform\Application\Tenants;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Application\PlatformSettings;
use App\Modules\Platform\Domain\TenantStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Features\FeatureFlags;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\CrossTenantMaintenance;
use App\Shared\Tenancy\Provisioning\ProvisioningRegistry;
use App\Shared\Tenancy\Provisioning\TenantSeed;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SAS-2 · Organizaciones desde la consola: alta con su primera empresa y su primer administrador,
 * edición de datos, cambios de estado por acciones (ME-40) y ficha con metadatos y consumo.
 *
 * La consola nunca ve sueldos, fichas ni documentos (RN-SAS-12): solo conteos y metadatos.
 */
final class TenantAdministration
{
    public const LOCALES = ['es', 'en', 'pt'];

    /** Acciones de la consola que exigen motivo (queda en el historial y en el aviso al cliente). */
    private const REASON_REQUIRED = [TenantStateMachine::SUSPEND, TenantStateMachine::BLOCK, TenantStateMachine::CANCEL];

    public function __construct(
        private readonly TenantStateMachine $machine,
        private readonly ConsoleAudit $audit,
        private readonly OutboxWriter $outbox,
        private readonly PlatformSettings $settings,
        private readonly ProvisioningRegistry $provisioning,
        private readonly CrossTenantMaintenance $cross,
        private readonly TenantModules $modules,
        private readonly FeatureFlags $features,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(int $page, int $perPage, ?string $status, ?string $q): array
    {
        $query = DB::table('tenants')->whereNull('deleted_at')
            ->when($status !== null && $status !== '', fn ($w) => $w->where('status', $status))
            ->when($q !== null && $q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', '%'.$q.'%')->orWhere('slug', 'ilike', '%'.mb_strtolower((string) $q).'%')));
        $total = (clone $query)->count();
        $rows = $query->orderBy('name')->forPage($page, $perPage)->get();

        $ids = array_values($rows->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $counts = $this->counts($ids);

        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->present($row) + ['usage' => $counts[(string) $row->id] ?? $this->emptyCounts()];
        }

        return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]];
    }

    /**
     * Ficha: datos, empresas, usuarios, colaboradores, estado de cuenta y módulos.
     *
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $row = $this->find($id);
        $tenantId = (string) $row->id;

        $entities = $this->cross->readAcrossTenants(fn () => DB::table('legal_entities')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->orderBy('legal_name')->get(['id', 'legal_name', 'tax_id', 'country_code', 'status']));

        return $this->present($row) + [
            'usage' => $this->counts([$tenantId])[$tenantId] ?? $this->emptyCounts(),
            'legal_entities' => $entities->map(fn ($e): array => [
                'id' => (string) $e->id, 'legal_name' => (string) $e->legal_name, 'tax_id' => (string) $e->tax_id,
                'country' => (string) $e->country_code, 'status' => (string) $e->status,
            ])->values()->all(),
            'account' => $this->account($tenantId),
            'modules' => $this->modules->summary($tenantId),
            'allowed_actions' => $this->machine->allowedActions((string) $row->status),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(array $input, PlatformUser $actor): array
    {
        $this->validateReferences($input);
        if (DB::table('tenants')->where('slug', $input['slug'])->whereNull('deleted_at')->exists()
            || in_array($input['slug'], (array) config('tenancy.reserved_subdomains', []), true)) {
            throw ValidationException::withMessages(['slug' => [(string) trans('platform.tenants.slug_taken')]]);
        }
        $trialEnds = empty($input['trial_ends_on']) ? null : Carbon::parse((string) $input['trial_ends_on'])->endOfDay();
        $status = $trialEnds !== null ? 'trial' : 'active';
        $moduleCodes = array_values(array_unique(array_map('strval', (array) ($input['module_codes'] ?? []))));

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $input, $trialEnds, $status, $moduleCodes, $actor): void {
            DB::table('tenants')->insert([
                'id' => $id,
                'slug' => $input['slug'],
                'name' => $input['name'],
                'status' => $status,
                'country_code' => $input['country'],
                'default_currency' => $input['currency'],
                'default_locale' => $input['locale'],
                'timezone' => $input['timezone'],
                'trial_ends_at' => $trialEnds,
                'contact_name' => $input['contact_name'] ?? null,
                'contact_email' => $input['contact_email'] ?? null,
                'contact_phone' => $input['contact_phone'] ?? null,
            ]);
            DB::table('tenant_status_events')->insert([
                'tenant_id' => $id, 'from_status' => null, 'to_status' => $status,
                'reason' => 'created', 'platform_user_id' => $actor->id,
            ]);

            // Roles de sistema, política de seguridad y módulos base; luego lo que aporta cada módulo.
            DB::select('SELECT seed_tenant_defaults(?)', [$id]);
            $seed = new TenantSeed(
                tenantId: $id, slug: (string) $input['slug'], countryCode: (string) $input['country'],
                currency: (string) $input['currency'], timezone: (string) $input['timezone'], locale: (string) $input['locale'],
                adminName: (string) $input['admin_name'], adminEmail: mb_strtolower((string) $input['admin_email']),
                legalName: (string) $input['legal_name'], taxId: (string) $input['tax_id'], payrollRegime: (string) $input['payroll_regime'],
            );
            TenancyScope::runAs($id, function () use ($seed): void {
                foreach ($this->provisioning->steps() as $step) {
                    $step->provision($seed);
                }
            });
            foreach ($moduleCodes as $code) {
                $this->modules->activate($id, $code, $actor, 'plan');
            }

            $this->audit->record('tenant.created', $actor, 'tenant', $id, null, [
                'name' => $input['name'], 'slug' => $input['slug'], 'status' => $status, 'country' => $input['country'],
                'modules' => $moduleCodes,
            ], $id);
        });

        return $this->show($id);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $id, array $input, PlatformUser $actor): array
    {
        $row = $this->find($id);
        $this->validateReferences($input + ['country' => $row->country_code, 'currency' => $row->default_currency], false);
        $map = ['name' => 'name', 'currency' => 'default_currency', 'locale' => 'default_locale', 'timezone' => 'timezone',
            'contact_name' => 'contact_name', 'contact_email' => 'contact_email', 'contact_phone' => 'contact_phone'];
        $changes = [];
        foreach ($map as $field => $column) {
            if (array_key_exists($field, $input)) {
                $changes[$column] = $input[$field];
            }
        }
        if (array_key_exists('beta_groups', $input)) {
            // SAS-7 · Grupos beta (códigos validados en el controlador): literal de arreglo de Postgres.
            $groups = array_values(array_unique(array_map('strval', (array) $input['beta_groups'])));
            sort($groups);
            $changes['beta_groups'] = '{'.implode(',', $groups).'}';
        }
        if (array_key_exists('trial_ends_on', $input) && $row->status === 'trial') {
            $changes['trial_ends_at'] = $input['trial_ends_on'] === null ? null : Carbon::parse((string) $input['trial_ends_on'])->endOfDay();
        }
        if ($changes !== []) {
            DB::transaction(function () use ($row, $changes, $actor): void {
                DB::table('tenants')->where('id', $row->id)->update($changes);
                $before = array_intersect_key((array) $row, $changes);
                $this->audit->record('tenant.updated', $actor, 'tenant', (string) $row->id, $before, $changes, (string) $row->id);
                if (array_key_exists('beta_groups', $changes)) {
                    $this->features->bumpVersion();
                }
            });
        }

        return $this->show($id);
    }

    /**
     * Cambio de estado por acción (regla 3): valida ME-40, guarda el historial inalterable, avisa a la
     * organización y deja la auditoría de la consola.
     *
     * @return array<string, mixed>
     */
    public function transition(string $id, string $action, ?string $reason, ?PlatformUser $actor): array
    {
        $row = $this->find($id);
        $from = (string) $row->status;
        $to = $this->machine->getNextState($from, $action);
        $reason = $reason === null ? null : trim($reason);
        if (in_array($action, self::REASON_REQUIRED, true) && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages(['reason' => [(string) trans('platform.tenants.reason_required')]]);
        }

        $retentionUntil = now()->addDays($this->settings->retentionDays((string) $row->country_code))->toDateString();
        $changes = match ($to) {
            'suspended', 'blocked' => ['suspended_at' => now(), 'suspension_reason' => $reason],
            'cancelled' => ['cancelled_at' => now(), 'retention_until' => $retentionUntil],
            // La fecha de fin de prueba solo vale en prueba; activa, la organización ya no está en solo lectura (SAS-4).
            'active' => ['suspended_at' => null, 'suspension_reason' => null, 'cancelled_at' => null, 'retention_until' => null, 'trial_ends_at' => null],
            default => [],
        };
        if ($from === 'cancelled' && $to === 'active' && $row->retention_until !== null && Carbon::parse((string) $row->retention_until)->isPast()) {
            // Vencida la retención, los datos ya pueden haberse eliminado: no se reactiva.
            throw new BusinessRuleViolatedException('RN-SAS-10', (string) trans('platform.tenants.retention_expired'));
        }

        DB::transaction(function () use ($row, $from, $to, $reason, $changes, $actor, $action, $retentionUntil): void {
            DB::table('tenants')->where('id', $row->id)->update(['status' => $to] + $changes);
            DB::table('tenant_status_events')->insert([
                'tenant_id' => $row->id, 'from_status' => $from, 'to_status' => $to,
                'reason' => $reason ?? $action, 'platform_user_id' => $actor?->id,
            ]);
            TenancyScope::runAs((string) $row->id, function () use ($row, $from, $to, $reason, $retentionUntil): void {
                $event = $this->outbox->record(DomainEventTypes::TENANT_STATUS_CHANGED, 'tenant', (string) $row->id, [
                    'tenant_id' => (string) $row->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason,
                    'retention_until' => $to === 'cancelled' ? $retentionUntil : null,
                ]);
                $this->outbox->deliverSoon($event);
            });
            $this->audit->record('tenant.'.$action, $actor, 'tenant', (string) $row->id, ['status' => $from], ['status' => $to, 'reason' => $reason], (string) $row->id);
        });

        return $this->show($id);
    }

    /**
     * SAS-4 · La suscripción arrastra el estado de la organización (ME-40) solo si la transición procede
     * desde el estado actual: si la consola ya la dejó ahí, no hace nada.
     */
    public function transitionIfAllowed(string $id, string $action, ?string $reason, ?PlatformUser $actor): void
    {
        $row = $this->find($id);
        if ($this->machine->can((string) $row->status, $action)) {
            $this->transition($id, $action, $reason, $actor);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statusEvents(string $id): array
    {
        $row = $this->find($id);

        return array_values(DB::table('tenant_status_events AS e')->leftJoin('platform_users AS u', 'u.id', '=', 'e.platform_user_id')
            ->where('e.tenant_id', $row->id)->orderByDesc('e.occurred_at')->orderByDesc('e.id')
            ->get(['e.id', 'e.from_status', 'e.to_status', 'e.reason', 'e.occurred_at', 'u.full_name'])
            ->map(fn ($e): array => [
                'id' => (string) $e->id,
                'from_status' => $e->from_status,
                'to_status' => (string) $e->to_status,
                'reason' => $e->reason,
                'actor' => $e->full_name,
                'occurred_at' => Carbon::parse((string) $e->occurred_at)->toIso8601String(),
            ])->all());
    }

    public function find(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('tenants')->where('id', $id)->whereNull('deleted_at')->first() : null;
        if (! $row instanceof \stdClass) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'slug' => (string) $row->slug,
            'country' => (string) $row->country_code,
            'currency' => (string) $row->default_currency,
            'timezone' => (string) $row->timezone,
            'locale' => (string) $row->default_locale,
            'status' => (string) $row->status,
            'is_demo' => (bool) $row->is_demo,
            'trial_ends_on' => $row->trial_ends_at === null ? null : Carbon::parse((string) $row->trial_ends_at)->toDateString(),
            'contact_name' => $row->contact_name,
            'contact_email' => $row->contact_email,
            'contact_phone' => $row->contact_phone,
            'suspension_reason' => $row->suspension_reason,
            'cancelled_at' => $row->cancelled_at === null ? null : Carbon::parse((string) $row->cancelled_at)->toIso8601String(),
            'retention_until' => $row->retention_until === null ? null : Carbon::parse((string) $row->retention_until)->toDateString(),
            'beta_groups' => self::pgArray($row->beta_groups ?? null),
            'version' => (int) $row->lock_version,
            'created_at' => Carbon::parse((string) $row->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse((string) $row->updated_at)->toIso8601String(),
        ];
    }

    /**
     * Conteos por organización, leídos entre organizaciones con el rol de plataforma (solo cifras).
     *
     * @param  list<string>  $ids
     * @return array<string, array{legal_entities: int, users: int, active_users: int, employees: int}>
     */
    private function counts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->cross->readAcrossTenants(function () use ($ids): array {
            $per = fn (string $table, ?callable $filter = null) => DB::table($table)->whereIn('tenant_id', $ids)->whereNull('deleted_at')
                ->when($filter !== null, $filter)->groupBy('tenant_id')->selectRaw('tenant_id, count(*) AS n')->pluck('n', 'tenant_id');
            $entities = $per('legal_entities');
            $users = $per('users');
            $active = $per('users', fn ($q) => $q->where('status', 'active'));
            $employees = $per('employments', fn ($q) => $q->whereIn('status', ['active', 'suspended', 'terminating']));

            $out = [];
            foreach ($ids as $id) {
                $out[$id] = [
                    'legal_entities' => (int) ($entities[$id] ?? 0),
                    'users' => (int) ($users[$id] ?? 0),
                    'active_users' => (int) ($active[$id] ?? 0),
                    'employees' => (int) ($employees[$id] ?? 0),
                ];
            }

            return $out;
        });
    }

    /**
     * @return array{legal_entities: int, users: int, active_users: int, employees: int}
     */
    private function emptyCounts(): array
    {
        return ['legal_entities' => 0, 'users' => 0, 'active_users' => 0, 'employees' => 0];
    }

    /**
     * Estado de cuenta del SaaS: suscripción vigente y saldo pendiente por moneda (SAS-11 lo completa).
     *
     * @return array<string, mixed>
     */
    private function account(string $tenantId): array
    {
        $subscription = DB::table('subscriptions AS s')->join('plans AS p', 'p.id', '=', 's.plan_id')
            ->where('s.tenant_id', $tenantId)->orderByDesc('s.starts_on')->first(['s.id', 's.status', 's.billing_cycle', 's.trial_ends_on', 'p.name']);
        $open = DB::table('saas_invoices')->where('tenant_id', $tenantId)->whereIn('status', ['issued', 'overdue'])
            ->groupBy('currency')->selectRaw('currency, sum(total)::text AS amount, count(*) AS n, sum(CASE WHEN status = \'overdue\' THEN 1 ELSE 0 END) AS overdue')->get();

        return [
            'subscription' => $subscription === null ? null : [
                'id' => (string) $subscription->id, 'plan' => (string) $subscription->name, 'status' => (string) $subscription->status,
                'billing_cycle' => (string) $subscription->billing_cycle, 'trial_ends_on' => $subscription->trial_ends_on,
            ],
            'open_balance' => $open->map(fn ($r): array => ['amount' => (string) $r->amount, 'currency' => (string) $r->currency])->values()->all(),
            'open_invoices' => (int) $open->sum('n'),
            'overdue_invoices' => (int) $open->sum('overdue'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validateReferences(array $input, bool $creating = true): void
    {
        $errors = [];
        if ($creating && ! DB::table('countries')->where('code', $input['country'] ?? '')->exists()) {
            $errors['country'][] = (string) trans('platform.tenants.unknown_country');
        }
        if (isset($input['currency']) && ! DB::table('currencies')->where('code', $input['currency'])->exists()) {
            $errors['currency'][] = (string) trans('platform.tenants.unknown_currency');
        }
        if (isset($input['timezone']) && ! in_array($input['timezone'], timezone_identifiers_list(), true)) {
            $errors['timezone'][] = (string) trans('platform.tenants.unknown_timezone');
        }
        if ($creating && ! DB::table('labor_regimes')->where('code', $input['payroll_regime'] ?? '')->where('country_code', $input['country'] ?? '')->exists()) {
            $errors['payroll_regime'][] = (string) trans('platform.tenants.unknown_regime');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Arreglo de texto de Postgres («{a,b}») → lista. Los grupos beta son códigos simples, sin comillas.
     *
     * @return list<string>
     */
    public static function pgArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $text = trim((string) $value, '{}');

        return $text === '' ? [] : array_map(fn (string $v): string => trim($v, '"'), explode(',', $text));
    }
}
