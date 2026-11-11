<?php

namespace App\Shared\Settings;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CFG-3 · Única forma de leer y cambiar parámetros de la organización: valor vigente a una fecha,
 * con herencia organización → empresa (CFG-2) y vigencia por fecha (regla 2: cambiar es cerrar la
 * fila vigente e insertar otra; nada se sobrescribe).
 */
final class ParameterResolver
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Valor vigente a una fecha: el de la empresa si lo define, si no el de la organización, si no el
     * valor por defecto del catálogo.
     */
    public function value(string $code, ?string $legalEntityId = null, ?string $asOf = null): mixed
    {
        return $this->resolve($code, $legalEntityId, $asOf)['value'];
    }

    /**
     * @return array{value: mixed, source: 'legal_entity'|'organization'|'default', valid_from: string|null, row_id: string|null}
     */
    public function resolve(string $code, ?string $legalEntityId = null, ?string $asOf = null): array
    {
        $date = $asOf ?? now()->toDateString();
        if ($legalEntityId !== null) {
            $row = $this->rowAt($code, 'legal_entity', $legalEntityId, $date);
            if ($row !== null) {
                return ['value' => $this->decode($row->value), 'source' => 'legal_entity', 'valid_from' => $row->valid_from, 'row_id' => (string) $row->id];
            }
        }
        $row = $this->rowAt($code, 'organization', null, $date);
        if ($row !== null) {
            return ['value' => $this->decode($row->value), 'source' => 'organization', 'valid_from' => $row->valid_from, 'row_id' => (string) $row->id];
        }

        return ['value' => $this->decode(DB::table('parameter_definitions')->where('code', $code)->value('default_value')), 'source' => 'default', 'valid_from' => null, 'row_id' => null];
    }

    /**
     * Historial de vigencias de un parámetro en un ámbito, del más reciente al más antiguo.
     *
     * @return array<int, array{value: mixed, valid_from: string|null, valid_to: string|null}>
     */
    public function history(string $code, string $scope = 'organization', ?string $legalEntityId = null): array
    {
        return $this->scoped($code, $scope, $legalEntityId)->orderByRaw('valid_from desc nulls last')->get(['value', 'valid_from', 'valid_to'])
            ->map(fn ($r) => ['value' => $this->decode($r->value), 'valid_from' => $r->valid_from, 'valid_to' => $r->valid_to])->all();
    }

    /**
     * Cambia el valor desde una fecha. La fila vigente se cierra el día anterior; los periodos
     * anteriores conservan su valor. null en una empresa = vuelve a heredar de la organización.
     */
    public function set(string $code, mixed $value, string $scope, ?string $legalEntityId, ?string $validFrom, ?string $reason): void
    {
        $definition = DB::table('parameter_definitions')->where('code', $code)->first();
        if (! $definition instanceof \stdClass) {
            throw new AyniException('not_found', 404);
        }
        $scopes = $this->parseArray((string) $definition->allowed_scopes);
        if (! in_array($scope, $scopes, true) || ($scope === 'legal_entity') !== ($legalEntityId !== null)) {
            throw ValidationException::withMessages(['scope' => [(string) trans('settings.scope_not_allowed')]]);
        }
        $inherit = $value === null && $scope !== 'organization';
        if (! $inherit) {
            $value = $this->validate($definition, $value);
        }
        $from = $validFrom ?? now()->toDateString();

        DB::transaction(function () use ($code, $value, $scope, $legalEntityId, $from, $reason, $inherit): void {
            $scheduled = $this->scoped($code, $scope, $legalEntityId)->where('valid_from', '>', $from)->exists();
            if ($scheduled) {
                throw new AyniException('conflict', 409, (string) trans('settings.later_value_exists'));
            }
            $current = $this->rowAt($code, $scope, $legalEntityId, $from);
            $before = $current === null ? null : ['value' => $this->decode($current->value), 'valid_from' => $current->valid_from];

            if ($current !== null && $current->valid_from === $from) {
                // Mismo día de inicio: se corrige la fila que empieza hoy (aún no tiene historia propia).
                if ($inherit) {
                    DB::table('tenant_settings')->where('id', $current->id)->delete();
                } else {
                    DB::table('tenant_settings')->where('id', $current->id)->update(['value' => json_encode($value)]);
                }
                $id = (string) $current->id;
            } else {
                if ($current !== null) {
                    DB::table('tenant_settings')->where('id', $current->id)->update(['valid_to' => Carbon::parse($from)->subDay()->toDateString()]);
                }
                $id = $inherit ? (string) ($current->id ?? '') : (string) DB::table('tenant_settings')->insertGetId([
                    'tenant_id' => TenancyScope::requireTenant(),
                    'parameter_code' => $code, 'scope_kind' => $scope, 'legal_entity_id' => $legalEntityId,
                    'value' => json_encode($value), 'valid_from' => $from,
                ]);
            }

            $this->audit->record('setting.changed', 'tenant_setting', $id !== '' ? $id : null, ($before ?? []) + ['code' => $code],
                ['code' => $code, 'scope' => $scope, 'legal_entity_id' => $legalEntityId, 'value' => $inherit ? null : $value, 'valid_from' => $from, 'inherits' => $inherit], $reason);
        });
    }

    private function rowAt(string $code, string $scope, ?string $legalEntityId, string $date): ?\stdClass
    {
        $row = $this->scoped($code, $scope, $legalEntityId)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->orderByRaw('valid_from desc nulls last')->first(['id', 'value', 'valid_from', 'valid_to']);

        return $row instanceof \stdClass ? $row : null;
    }

    private function scoped(string $code, string $scope, ?string $legalEntityId): Builder
    {
        $q = DB::table('tenant_settings')->where('parameter_code', $code)->where('scope_kind', $scope);

        return $legalEntityId === null ? $q->whereNull('legal_entity_id') : $q->where('legal_entity_id', $legalEntityId);
    }

    private function validate(\stdClass $definition, mixed $value): mixed
    {
        $options = $definition->options === null ? null : json_decode((string) $definition->options, true);
        $ok = match ((string) $definition->data_type) {
            'boolean' => is_bool($value),
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'decimal' => is_int($value) || (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value) === 1),
            'text' => is_string($value) && mb_strlen($value) <= 500,
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1,
            'time' => is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1,
            'select' => is_string($value) && (! is_array($options) || ! array_is_list($options) || in_array($value, $options, true)),
            'list' => is_array($value) && array_is_list($value) && (! is_array($options) || array_diff($value, $options) === []),
            'json' => is_array($value),
            default => false,
        };
        // Márgenes que fija la plataforma (options {"min", "max"} en el catálogo de parámetros).
        if ($ok && in_array((string) $definition->data_type, ['integer', 'decimal'], true) && is_array($options) && ! array_is_list($options)) {
            $n = (float) $value;
            $ok = (! isset($options['min']) || $n >= (float) $options['min']) && (! isset($options['max']) || $n <= (float) $options['max']);
        }
        if (! $ok) {
            throw ValidationException::withMessages(['value' => [(string) trans('settings.invalid_value')]]);
        }

        // Montos y decimales como texto exacto, nunca float (regla 12).
        return match ((string) $definition->data_type) {
            'integer' => (int) $value,
            'decimal' => (string) $value,
            default => $value,
        };
    }

    private function decode(mixed $raw): mixed
    {
        return is_string($raw) ? json_decode($raw, true) : $raw;
    }

    /**
     * @return array<int, string>
     */
    private function parseArray(string $pg): array
    {
        return array_values(array_filter(explode(',', trim($pg, '{}'))));
    }
}
