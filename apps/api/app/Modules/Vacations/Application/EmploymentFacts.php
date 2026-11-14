<?php

namespace App\Modules\Vacations\Application;

use App\Shared\Exceptions\AyniException;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;

/**
 * VAC-2 · Lo que el módulo necesita saber de una relación laboral, leído de las tablas compartidas con
 * la RLS de la organización activa (sin importar modelos de CoreHR): fechas, estado, régimen con
 * vigencia y categoría laboral del puesto vigente.
 */
final class EmploymentFacts
{
    /**
     * @return array{id: string, person_id: string, legal_entity_id: string, hire_date: string, seniority_date: string|null, termination_date: string|null, status: string}
     */
    public function get(string $employmentId): array
    {
        $row = DB::table('employments')->where('id', $employmentId)->whereNull('deleted_at')
            ->first(['id', 'person_id', 'legal_entity_id', 'hire_date', 'seniority_date', 'termination_date', 'status']);
        if ($row === null) {
            throw new AyniException('not_found', 404, (string) trans('vacations.employment_not_found'));
        }

        return [
            'id' => (string) $row->id,
            'person_id' => (string) $row->person_id,
            'legal_entity_id' => (string) $row->legal_entity_id,
            'hire_date' => substr((string) $row->hire_date, 0, 10),
            'seniority_date' => $row->seniority_date === null ? null : substr((string) $row->seniority_date, 0, 10),
            'termination_date' => $row->termination_date === null ? null : substr((string) $row->termination_date, 0, 10),
            'status' => (string) $row->status,
        ];
    }

    /**
     * Fecha desde la que corren los años vacacionales, según el paquete de país.
     *
     * @param  array{hire_date: string, seniority_date: string|null}  $employment
     */
    public function anchor(array $employment, string $country): string
    {
        $rule = VacationPolicyCatalog::countryPackage($country)['ancla'] ?? 'hire_date';

        return $rule === 'seniority_date_or_hire_date' && $employment['seniority_date'] !== null ? $employment['seniority_date'] : $employment['hire_date'];
    }

    /**
     * Contexto para elegir la política a una fecha: empresa, régimen vigente y categoría del puesto vigente.
     *
     * @param  array{id: string, legal_entity_id: string}  $employment
     * @return array{legal_entity_id: string, labor_regime_code: string|null, job_category_id: string|null}
     */
    public function contextAt(array $employment, string $date): array
    {
        $regime = DB::table('employment_labor_regimes')->where('employment_id', $employment['id']);
        EffectiveDated::applyAsOf($regime, $date);
        $assignment = DB::table('employment_assignments')->where('employment_id', $employment['id']);
        EffectiveDated::applyAsOf($assignment, $date);
        $profile = $assignment->value('job_profile_id');

        return [
            'legal_entity_id' => $employment['legal_entity_id'],
            'labor_regime_code' => ($r = $regime->value('labor_regime_code')) === null ? null : (string) $r,
            'job_category_id' => $profile === null ? null : (($c = DB::table('job_profiles')->where('id', $profile)->value('job_category_id')) === null ? null : (string) $c),
        ];
    }

    /**
     * Fechas en que puede cambiar lo que corresponde dentro de [$from, $to]: cambios de régimen, de puesto,
     * de reglas de política y de los parámetros legales de días por año. Siempre incluye $from.
     *
     * @return list<string>
     */
    public function breakpoints(string $employmentId, string $from, string $to, string $country, string $legalCode): array
    {
        $dates = [$from];
        $add = function (?string $d) use (&$dates, $from, $to): void {
            if ($d !== null && $d > $from && $d <= $to) {
                $dates[] = $d;
            }
        };
        $next = fn ($d): ?string => $d === null ? null : (new \DateTimeImmutable(substr((string) $d, 0, 10)))->modify('+1 day')->format('Y-m-d');
        foreach (['employment_labor_regimes', 'employment_assignments'] as $table) {
            foreach (DB::table($table)->where('employment_id', $employmentId)->get(['valid_from', 'valid_to']) as $r) {
                $add(substr((string) $r->valid_from, 0, 10));
                $add($next($r->valid_to));
            }
        }
        foreach (DB::table('vacation_policy_rules')->get(['valid_from', 'valid_to']) as $r) {
            $add(substr((string) $r->valid_from, 0, 10));
            $add($next($r->valid_to));
        }
        foreach (DB::table('legal_parameters')->where('country_code', $country)->where('code', $legalCode)->get(['valid_from', 'valid_to']) as $r) {
            $add(substr((string) $r->valid_from, 0, 10));
            $add($next($r->valid_to));
        }
        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }
}
