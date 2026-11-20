<?php

namespace App\Modules\Payroll\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * PAR-1 · Parámetros legales con vigencia vistos por la organización: valor vigente, historial de valores con su
 * norma, estado «por validar», valor propio donde la norma lo permite (RN-PAR-07) y confirmación expresa de un
 * parámetro «por validar» antes de cerrar un periodo que lo usa (RN-PAR-03). Los valores del país los mantiene
 * el producto (legal_parameters, solo lectura para la organización); la organización solo gestiona los propios.
 */
final class LegalParameterCatalog
{
    public function __construct(private readonly LegalParameters $legal, private readonly AuditLogger $audit) {}

    /**
     * País de la organización: el de sus empresas (la tabla de organizaciones es de la plataforma).
     */
    public static function country(): string
    {
        $country = DB::table('legal_entities')->whereNull('deleted_at')->orderBy('created_at')->value('country_code');

        return is_string($country) && $country !== '' ? $country : (string) config('app.default_country', 'PE');
    }

    /**
     * Un elemento por parámetro y régimen con su valor vigente a la fecha, el valor propio y si está confirmado.
     *
     * @param  array{code?: string|null, regime?: string|null, pending?: bool, search?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(string $asOf, array $filters = []): array
    {
        $country = self::country();
        $q = DB::table('legal_parameters')->where('country_code', $country);
        EffectiveDated::applyAsOf($q, $asOf);
        if (($filters['code'] ?? null) !== null) {
            $q->where('code', $filters['code']);
        }
        if (($filters['regime'] ?? null) !== null) {
            $q->where(fn ($w) => $w->whereNull('labor_regime_code')->orWhere('labor_regime_code', $filters['regime']));
        }
        if (($filters['search'] ?? null) !== null && $filters['search'] !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']).'%';
            $q->where(fn ($w) => $w->where('code', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }
        $rows = $q->orderBy('code')->orderByRaw('labor_regime_code NULLS FIRST')->get();
        $confirmed = DB::table('legal_parameter_confirmations')->whereIn('legal_parameter_id', $rows->pluck('id')->all())->pluck('legal_parameter_id')->map(fn ($v) => (string) $v)->flip();
        $out = [];
        foreach ($rows as $r) {
            $item = $this->present($r, $asOf);
            $item['confirmed'] = $confirmed->has((string) $r->id);
            if (($filters['pending'] ?? false) && ! $item['pending_validation']) {
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        $row = $this->find($id);
        $item = $this->present($row, now()->toDateString());
        $item['history'] = $this->legal->history((string) $row->country_code, (string) $row->code, $row->labor_regime_code === null ? null : (string) $row->labor_regime_code);
        $item['own_values'] = DB::table('legal_parameter_overrides')->where('parameter_code', $row->code)->orderByDesc('valid_from')->get()
            ->map(fn ($o): array => [
                'id' => (string) $o->id, 'legal_entity_id' => $o->legal_entity_id === null ? null : (string) $o->legal_entity_id,
                'value' => $o->value_numeric !== null ? self::num((string) $o->value_numeric) : ($o->value_json === null ? null : json_decode((string) $o->value_json, true)),
                'reason' => (string) $o->reason, 'valid_from' => substr((string) $o->valid_from, 0, 10), 'valid_to' => $o->valid_to === null ? null : substr((string) $o->valid_to, 0, 10),
            ])->values()->all();
        $item['confirmations'] = DB::table('legal_parameter_confirmations AS c')->leftJoin('users AS u', 'u.id', '=', 'c.confirmed_by_user_id')
            ->where('c.legal_parameter_id', $row->id)->orderByDesc('c.created_at')
            ->get(['c.id', 'c.note', 'c.created_at', 'u.display_name'])->map(fn ($c): array => [
                'id' => (string) $c->id, 'note' => $c->note === null ? null : (string) $c->note, 'confirmed_by' => (string) ($c->display_name ?? ''),
                'confirmed_at' => CarbonImmutable::parse((string) $c->created_at)->toIso8601String(),
            ])->values()->all();
        $item['confirmed'] = $item['confirmations'] !== [];
        $item['adjustable'] = self::adjustable((string) $row->code);

        return $item;
    }

    /**
     * RN-PAR-07 · Valor propio de la organización o de una empresa con vigencia. Solo en los parámetros que la
     * norma permite mejorar y solo en la dirección más favorable; cerrar e insertar (regla 2).
     *
     * @param  array<string, mixed>  $input  value, valid_from, reason, legal_entity_id?
     * @return array<string, mixed>
     */
    public function addValue(string $id, array $input, string $userId): array
    {
        $row = $this->find($id);
        $direction = self::adjustable((string) $row->code);
        if ($direction === null) {
            throw new AyniException('parameter_not_adjustable', 409, (string) trans('payroll.parameters.not_adjustable'));
        }
        $value = $input['value'] ?? null;
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(['value' => trans('payroll.parameters.value_numeric')]);
        }
        $from = (string) ($input['valid_from'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
            throw ValidationException::withMessages(['valid_from' => trans('payroll.parameters.invalid_date')]);
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => trans('payroll.parameters.reason_required')]);
        }
        $entity = $input['legal_entity_id'] ?? null;
        if ($entity !== null && (! TenancyScope::isValidUuid((string) $entity) || ! DB::table('legal_entities')->where('id', $entity)->whereNull('deleted_at')->exists())) {
            throw ValidationException::withMessages(['legal_entity_id' => trans('payroll.parameters.unknown_entity')]);
        }
        $legal = $this->legal->at((string) $row->country_code, (string) $row->code, $from, $row->labor_regime_code === null ? null : (string) $row->labor_regime_code);
        if ($legal !== null && is_numeric($legal['value'])) {
            $cmp = BigDecimal::of((string) $value)->compareTo(BigDecimal::of((string) $legal['value']));
            if (($direction === 'mayor' && $cmp < 0) || ($direction === 'menor' && $cmp > 0)) {
                throw ValidationException::withMessages(['value' => trans('payroll.parameters.less_favorable', ['legal' => (string) $legal['value']])]);
            }
        }

        return DB::transaction(function () use ($row, $value, $from, $reason, $entity, $userId): array {
            $q = DB::table('legal_parameter_overrides')->where('parameter_code', $row->code);
            $entity === null ? $q->whereNull('legal_entity_id') : $q->where('legal_entity_id', $entity);
            $last = $q->orderByDesc('valid_from')->lockForUpdate()->first(['id', 'valid_from', 'valid_to']);
            if ($last !== null && substr((string) $last->valid_from, 0, 10) >= $from) {
                throw new AyniException('own_value_overlap', 409, (string) trans('payroll.parameters.own_value_overlap'));
            }
            if ($last !== null && ($last->valid_to === null || substr((string) $last->valid_to, 0, 10) >= $from)) {
                DB::table('legal_parameter_overrides')->where('id', $last->id)->update(['valid_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
            }
            $newId = (string) Str::uuid();
            DB::table('legal_parameter_overrides')->insert([
                'id' => $newId, 'tenant_id' => TenancyScope::requireTenant(), 'legal_entity_id' => $entity, 'parameter_code' => $row->code,
                'value_numeric' => (string) $value, 'reason' => $reason, 'approved_by_user_id' => $userId, 'valid_from' => $from,
            ]);
            $this->audit->record('legal_parameter.own_value_added', 'legal_parameter_override', $newId, null,
                ['parameter_code' => (string) $row->code, 'value' => (string) $value, 'valid_from' => $from, 'legal_entity_id' => $entity, 'reason' => $reason]);

            return ['id' => $newId, 'parameter_code' => (string) $row->code, 'value' => self::num((string) $value), 'valid_from' => $from, 'legal_entity_id' => $entity, 'reason' => $reason];
        });
    }

    /**
     * RN-PAR-03 · Confirmación expresa de un parámetro «por validar» antes de un cierre (solo inserción).
     *
     * @return array<string, mixed>
     */
    public function confirm(string $id, ?string $note, string $userId): array
    {
        $row = $this->find($id);
        $value = LegalParameters::valueOf($row);
        if ((bool) $row->verified && $value !== null) {
            throw new AyniException('parameter_already_verified', 409, (string) trans('payroll.parameters.already_verified'));
        }
        if ($value === null) {
            throw new AyniException('parameter_without_value', 409, (string) trans('payroll.parameters.without_value'));
        }
        if (DB::table('legal_parameter_confirmations')->where('legal_parameter_id', $row->id)->exists()) {
            throw new AyniException('parameter_already_confirmed', 409, (string) trans('payroll.parameters.already_confirmed'));
        }
        $newId = (string) Str::uuid();
        DB::table('legal_parameter_confirmations')->insert([
            'id' => $newId, 'tenant_id' => TenancyScope::requireTenant(), 'legal_parameter_id' => $row->id, 'parameter_code' => $row->code,
            'confirmed_by_user_id' => $userId, 'note' => $note === null || trim($note) === '' ? null : trim($note),
        ]);
        $this->audit->record('legal_parameter.confirmed', 'legal_parameter', (string) $row->id, null, ['parameter_code' => (string) $row->code, 'value' => $value, 'note' => $note]);

        return $this->show($id);
    }

    /**
     * Dirección favorable si la organización puede registrar un valor propio; null si no puede.
     */
    public static function adjustable(string $code): ?string
    {
        $file = base_path('packages/country-'.strtolower(self::country()).'/data/parametros_ajustables.php');
        if (! is_file($file)) {
            return null;
        }
        /** @var array{parametros?: array<string, string>} $data */
        $data = require $file;

        return $data['parametros'][$code] ?? null;
    }

    public function find(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('legal_parameters')->where('id', $id)->where('country_code', self::country())->first() : null;
        if ($row === null) {
            throw new AyniException('not_found', 404, (string) trans('payroll.parameters.not_found'));
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $r, string $asOf): array
    {
        $value = LegalParameters::valueOf($r);

        return [
            'id' => (string) $r->id, 'code' => (string) $r->code, 'name' => (string) $r->name, 'country' => (string) $r->country_code,
            'regime' => $r->labor_regime_code === null ? null : (string) $r->labor_regime_code, 'value' => $value, 'unit' => (string) $r->unit,
            'currency' => $r->currency === null ? null : (string) $r->currency, 'valid_from' => substr((string) $r->valid_from, 0, 10),
            'valid_to' => $r->valid_to === null ? null : substr((string) $r->valid_to, 0, 10), 'legal_source' => $r->legal_source === null ? null : (string) $r->legal_source,
            'verified' => (bool) $r->verified, 'pending_validation' => ! (bool) $r->verified || $value === null,
            'notes' => $r->notes === null ? null : (string) $r->notes, 'as_of' => $asOf,
        ];
    }

    private static function num(string $v): string
    {
        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }
}
