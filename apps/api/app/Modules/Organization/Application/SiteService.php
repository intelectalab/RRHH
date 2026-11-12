<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\SiteStateMachine;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Organization\Infrastructure\Models\Site;
use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\VersionConflictException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORG-2 · Sedes, sucursales y ubicaciones por empresa: dirección, ubigeo, coordenadas y radio de la
 * geocerca de marcación, zona horaria, calendario de feriados y marca de centro de riesgo (SCTR).
 *
 * - RN-ORG-05: una sede con relaciones laborales activas no se archiva.
 * - Archivada conserva su historia (asignaciones pasadas intactas) y no admite nuevas asignaciones
 *   (StructureAvailability).
 * - El estado cambia solo por acciones (archive / restore).
 */
final class SiteService
{
    /** Campos editables: nombre de la API => columna. */
    public const FIELDS = [
        'legal_entity_id' => 'legal_entity_id', 'code' => 'code', 'name' => 'name', 'kind' => 'kind', 'address' => 'address',
        'district_code' => 'district_code', 'country' => 'country_code', 'latitude' => 'latitude', 'longitude' => 'longitude',
        'geofence_radius_m' => 'geofence_radius_m', 'timezone' => 'timezone', 'holiday_calendar_id' => 'holiday_calendar_id',
        'establishment_code' => 'establishment_code', 'risk_site' => 'is_risk_site',
    ];

    public function __construct(
        private readonly SiteStateMachine $machine,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Site $site): array
    {
        $entityTz = $site->legal_entity_id === null ? null : LegalEntity::query()->whereKey($site->legal_entity_id)->value('timezone');
        $tenantTz = DB::table('current_tenant')->value('timezone');

        return [
            'id' => $site->id,
            'legal_entity_id' => $site->legal_entity_id,
            'code' => $site->code,
            'name' => $site->name,
            'kind' => $site->kind,
            'address' => $site->address,
            'district_code' => $site->district_code,
            'country' => $site->country_code,
            'latitude' => $site->latitude === null ? null : (float) $site->latitude,
            'longitude' => $site->longitude === null ? null : (float) $site->longitude,
            'geofence_radius_m' => $site->geofence_radius_m,
            'timezone' => $site->timezone,
            'effective_timezone' => $site->timezone ?? (is_string($entityTz) ? $entityTz : (is_string($tenantTz) ? $tenantTz : 'UTC')),
            'holiday_calendar_id' => $site->holiday_calendar_id,
            'establishment_code' => $site->establishment_code,
            'risk_site' => $site->is_risk_site,
            'status' => $site->status === 'active' ? 'active' : 'archived',
            'allowed_actions' => $this->machine->allowedActions($site->status),
            'version' => $site->lock_version,
            'created_at' => $site->created_at->toIso8601String(),
            'updated_at' => $site->updated_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input  ya validado (claves de la API)
     */
    public function create(array $input): Site
    {
        $values = $this->columns($input);
        $values['country_code'] ??= $this->entityCountry($values['legal_entity_id'] ?? null);
        $this->check($values, null);

        return DB::transaction(function () use ($values): Site {
            $site = Site::create($values + ['status' => 'active']);
            $this->audit->record('site.created', 'site', $site->id, null, $this->present($site->refresh()));

            return $site;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Site $site, array $input, ?int $expectedVersion): Site
    {
        if ($expectedVersion !== null && $expectedVersion !== $site->lock_version) {
            throw new VersionConflictException($expectedVersion, $site->lock_version);
        }
        if ($site->status !== 'active') {
            throw new AyniException('invalid_transition', 409, null, [], ['current_status' => 'archived', 'allowed_actions' => ['restore']]);
        }
        $values = $this->columns($input);
        $merged = array_merge($site->only(array_values(self::FIELDS)), $values);
        $this->check($merged, $site);

        if (array_key_exists('legal_entity_id', $values) && $values['legal_entity_id'] !== $site->legal_entity_id && $this->activeEmployments($site) > 0) {
            throw ValidationException::withMessages(['legal_entity_id' => [(string) trans('organization.sites.company_in_use')]]);
        }

        return DB::transaction(function () use ($site, $values): Site {
            $before = $this->present($site);
            $site->fill($values);
            if ($site->isDirty()) {
                $site->save();
            }
            $site->refresh();
            $this->audit->record('site.updated', 'site', $site->id, $before, $this->present($site));

            return $site;
        });
    }

    public function transition(Site $site, string $action, ?string $reason): Site
    {
        $next = $this->machine->getNextState($site->status, $action);
        if ($action === 'archive') {
            $count = $this->activeEmployments($site);
            if ($count > 0) {
                throw new BusinessRuleViolatedException('RN-ORG-05', (string) trans('organization.sites.has_active_employments', ['count' => $count]));
            }
        }

        return DB::transaction(function () use ($site, $action, $next, $reason): Site {
            $before = $this->present($site);
            $site->forceFill(['status' => $next])->save();
            $site->refresh();
            $this->audit->record('site.'.($action === 'archive' ? 'archived' : 'restored'), 'site', $site->id, $before, $this->present($site) + ['reason' => $reason]);

            return $site;
        });
    }

    /** Relaciones laborales activas (o suspendidas o en cese) asignadas hoy a la sede. */
    public function activeEmployments(Site $site): int
    {
        $today = now()->toDateString();

        return (int) DB::table('employment_assignments as a')
            ->join('employments as e', 'e.id', '=', 'a.employment_id')
            ->where('a.site_id', $site->id)
            ->whereIn('e.status', ['active', 'suspended', 'terminating'])
            ->whereNull('e.deleted_at')
            ->where('a.valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('a.valid_to')->orWhere('a.valid_to', '>=', $today))
            ->distinct()->count('a.employment_id');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function columns(array $input): array
    {
        $values = [];
        foreach (self::FIELDS as $in => $column) {
            if (array_key_exists($in, $input)) {
                $values[$column] = $input[$in];
            }
        }

        return $values;
    }

    private function entityCountry(mixed $legalEntityId): ?string
    {
        if (! is_string($legalEntityId)) {
            return null;
        }
        $country = LegalEntity::query()->whereKey($legalEntityId)->value('country_code');

        return is_string($country) ? $country : null;
    }

    /**
     * Reglas que dependen de otros datos (las de forma están en el FormRequest).
     *
     * @param  array<string, mixed>  $v  columnas
     */
    private function check(array $v, ?Site $site): void
    {
        $errors = [];
        $entityId = $v['legal_entity_id'] ?? null;
        if (is_string($entityId) && (! $site instanceof Site || $entityId !== $site->legal_entity_id)
            && ! LegalEntity::query()->whereKey($entityId)->where('status', 'active')->exists()) {
            $errors['legal_entity_id'] = [(string) trans('organization.sites.company_not_active')];
        }
        $code = $v['code'] ?? null;
        if (is_string($code) && Site::query()->whereRaw('lower(code) = lower(?)', [$code])
            ->when($site instanceof Site, fn ($q) => $q->whereKeyNot($site?->id))->exists()) {
            $errors['code'] = [(string) trans('organization.sites.duplicate_code')];
        }
        $lat = $v['latitude'] ?? null;
        $lng = $v['longitude'] ?? null;
        if (($lat === null) !== ($lng === null)) {
            $errors['latitude'] = [(string) trans('organization.sites.coordinates_pair')];
        }
        if (($v['geofence_radius_m'] ?? null) !== null && $lat === null) {
            $errors['geofence_radius_m'] = [(string) trans('organization.sites.radius_needs_coordinates')];
        }
        $country = $v['country_code'] ?? null;
        $district = $v['district_code'] ?? null;
        if ($country === 'PE' && is_string($district) && preg_match('/^\d{6}$/', $district) !== 1) {
            $errors['district_code'] = [(string) trans('organization.sites.invalid_ubigeo')];
        }
        $establishment = $v['establishment_code'] ?? null;
        if ($country === 'PE' && is_string($establishment) && preg_match('/^\d{4}$/', $establishment) !== 1) {
            $errors['establishment_code'] = [(string) trans('organization.sites.invalid_establishment')];
        }
        $calendar = $v['holiday_calendar_id'] ?? null;
        if (is_string($calendar) && ! DB::table('holiday_calendars')->where('id', $calendar)->whereNull('deleted_at')->exists()) {
            $errors['holiday_calendar_id'] = [(string) trans('organization.sites.calendar_not_found')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
