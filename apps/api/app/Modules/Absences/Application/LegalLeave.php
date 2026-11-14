<?php

namespace App\Modules\Absences\Application;

use App\Modules\Absences\Infrastructure\Models\AbsenceType;
use App\Shared\LegalParameters\LegalParameters;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AUS-4 · RN-AUS-04 · Licencias de ley: duración desde los parámetros legales con vigencia, ampliaciones por
 * causal, documentos que se piden y fecha de fin propuesta.
 *
 * La duración base sale del parámetro del tipo (legal_duration_parameter_code). Cada causal elegida suma días
 * (mode add) o fija la duración completa (mode total, se toma la mayor). Si alguna pieza no tiene valor
 * verificado, la duración queda «por validar»: se propone si se conoce, pero no bloquea la solicitud.
 * Las causales y documentos de cada tipo vienen del paquete de país (packages/country-xx/data/licencias_ley.php).
 */
final class LegalLeave
{
    public function __construct(private readonly LegalParameters $legal) {}

    /**
     * Causales y documentos que el paquete de país define para un tipo (por su código).
     *
     * @return array{extensions: list<array{code: string, parameter: string, mode: string}>, documents: list<string>}
     */
    public static function definition(?string $country, string $typeCode): array
    {
        $empty = ['extensions' => [], 'documents' => []];
        if ($country === null || preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return $empty;
        }
        $file = base_path('packages/country-'.strtolower($country).'/data/licencias_ley.php');
        if (! is_file($file)) {
            return $empty;
        }
        /** @var array<string, array{extensions: list<array{code: string, parameter: string, mode: string}>, documents: list<string>}> $data */
        $data = require $file;

        return $data[$typeCode] ?? $empty;
    }

    /**
     * Plan de la licencia: duración base, ampliaciones elegidas, total, documentos y si queda por validar.
     * null si el tipo no es una licencia de ley ni tiene causales o documentos de ley.
     *
     * @param  list<string>  $extensions  códigos de causal elegidos
     * @return array{base_parameter: string|null, base_days: string|null, extensions: list<array{code: string, label: string, parameter: string, mode: string, days: string|null, pending_validation: bool, selected: bool}>, total_days: string|null, pending_validation: bool, verified: bool, documents: list<array{code: string, label: string}>}|null
     */
    public function plan(AbsenceType $type, CarbonInterface|string $asOf, array $extensions = [], ?string $regime = null): ?array
    {
        $country = $type->country_code ?? $this->country();
        $definition = self::definition($country, $type->code);
        $baseCode = $type->legal_duration_parameter_code !== null && $type->legal_duration_parameter_code !== '' ? $type->legal_duration_parameter_code : null;
        if ($baseCode === null && $definition['extensions'] === [] && $definition['documents'] === []) {
            return null;
        }
        $known = array_map(fn (array $e): string => $e['code'], $definition['extensions']);
        $unknown = array_values(array_diff($extensions, $known));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['legal_extensions' => [(string) trans('absences.legal_leave.unknown_extension', ['code' => implode(', ', $unknown)])]]);
        }

        $pending = false;
        $verified = true;
        $base = null;
        if ($baseCode !== null) {
            $p = $this->legal->at((string) $country, $baseCode, $asOf, $regime);
            $base = $p === null || ! is_string($p['value']) ? null : $p['value'];
            $pending = $p === null || $p['pending_validation'];
            $verified = $p !== null && $p['verified'];
        }

        $rows = [];
        $total = $base === null ? null : BigDecimal::of($base);
        foreach ($definition['extensions'] as $ext) {
            $p = $this->legal->at((string) $country, $ext['parameter'], $asOf, $regime);
            $days = $p === null || ! is_string($p['value']) ? null : $p['value'];
            $selected = in_array($ext['code'], $extensions, true);
            $rows[] = [
                'code' => $ext['code'], 'label' => (string) trans('absences.legal_leave.extensions.'.$ext['code']),
                'parameter' => $ext['parameter'], 'mode' => $ext['mode'], 'days' => $days,
                'pending_validation' => $p === null || $p['pending_validation'], 'selected' => $selected,
            ];
            if (! $selected) {
                continue;
            }
            $pending = $pending || $p === null || $p['pending_validation'];
            $verified = $verified && $p !== null && $p['verified'];
            if ($days === null) {
                $total = null;

                continue;
            }
            if ($total === null && $ext['mode'] === 'add') {
                continue;
            }
            $total = $ext['mode'] === 'add'
                ? $total->plus($days)
                : ($total === null || BigDecimal::of($days)->isGreaterThan($total) ? BigDecimal::of($days) : $total);
        }

        return [
            'base_parameter' => $baseCode,
            'base_days' => $base,
            'extensions' => $rows,
            'total_days' => $total === null ? null : self::number((string) $total),
            'pending_validation' => $pending,
            'verified' => $verified && ! $pending,
            'documents' => array_map(fn (string $code): array => ['code' => $code, 'label' => (string) trans('absences.legal_leave.documents.'.$code)], $definition['documents']),
        ];
    }

    /**
     * Último día propuesto: inicio + duración total − 1 (días naturales), o null si la duración no se conoce.
     *
     * @param  array{total_days: string|null}|null  $plan
     */
    public static function proposedEnd(?array $plan, string $startsOn): ?string
    {
        if ($plan === null || $plan['total_days'] === null) {
            return null;
        }
        $days = (int) BigDecimal::of($plan['total_days'])->toScale(0, RoundingMode::CEILING)->toInt();
        if ($days < 1) {
            return null;
        }

        return CarbonImmutable::parse($startsOn)->addDays($days - 1)->toDateString();
    }

    private function country(): string
    {
        return (string) (DB::table('current_tenant')->value('country_code') ?? '');
    }

    private static function number(string $s): string
    {
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
