<?php

namespace App\Modules\Certificates\Application;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ESS-4 · Datos y texto de cada constancia. Lee la ficha vigente (relación laboral, persona, empresa y
 * puesto a la fecha de emisión); la de remuneraciones lee además el sueldo vigente y deja la lectura en
 * el registro de accesos (regla 7). La de vacaciones lista los periodos gozados; no calcula saldos.
 */
final class CertificateContent
{
    /** Periodos gozados que se listan en la constancia de vacaciones (los más recientes). */
    private const VACATION_PERIODS = 12;

    /**
     * @return array{title: string, body: string, footer: string, variables: array<string, string>}
     */
    public function build(string $kind, string $employmentId, ?string $purpose, string $locale, ?string $readerUserId): array
    {
        $today = now()->toDateString();
        $e = DB::table('employments')->where('id', $employmentId)->first(['id', 'person_id', 'legal_entity_id', 'employee_code', 'hire_date', 'termination_date']);
        abort_if($e === null, 404);
        $p = DB::table('people')->where('id', $e->person_id)->first(['first_names', 'paternal_surname', 'maternal_surname']);
        $le = DB::table('legal_entities')->where('id', $e->legal_entity_id)->first(['legal_name', 'tax_id', 'address']);
        $job = DB::table('employment_assignments as a')
            ->leftJoin('positions as pos', 'pos.id', '=', 'a.position_id')
            ->leftJoin('job_profiles as jp', 'jp.id', '=', 'a.job_profile_id')
            ->where('a.employment_id', $employmentId)->where('a.valid_from', '<=', $today)
            ->orderByDesc('a.valid_from')->selectRaw('COALESCE(pos.title, jp.name) as title')->value('title');

        $format = (string) trans('certificates.date_format', [], $locale);
        $date = function (?string $d) use ($locale, $format): string {
            if ($d === null) {
                return '';
            }
            $c = Carbon::parse(substr($d, 0, 10));
            $c->setLocale($locale);

            return $c->translatedFormat($format);
        };
        $vars = [
            'employee_name' => trim(($p->first_names ?? '').' '.($p->paternal_surname ?? '').' '.($p->maternal_surname ?? '')),
            'employee_code' => (string) $e->employee_code,
            'legal_entity_name' => (string) ($le->legal_name ?? ''),
            'legal_entity_tax_id' => (string) ($le->tax_id ?? ''),
            'legal_entity_address' => (string) ($le->address ?? ''),
            'job_title' => is_string($job) && $job !== '' ? $job : (string) trans('certificates.no_job', [], $locale),
            'hire_date' => $date((string) $e->hire_date),
            'termination_date' => $date($e->termination_date === null ? null : (string) $e->termination_date),
            'issued_on' => $date($today),
            'purpose' => (string) ($purpose ?? ''),
        ];
        $ended = $e->termination_date !== null && substr((string) $e->termination_date, 0, 10) < $today;

        $lines = [(string) trans('certificates.body.intro', $vars, $locale), ''];
        $lines[] = (string) trans($ended ? 'certificates.body.employment_ended' : 'certificates.body.employment_current', $vars, $locale);

        if ($kind === 'income_certificate') {
            $salary = DB::table('employment_compensations')->where('employment_id', $employmentId)
                ->where('valid_from', '<=', $today)->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))
                ->orderByDesc('valid_from')->first(['id', 'base_salary', 'currency']);
            if ($salary !== null) {
                $vars['salary'] = $salary->currency.' '.self::amount((string) $salary->base_salary);
                $lines[] = '';
                $lines[] = (string) trans('certificates.body.salary', $vars, $locale);
                // Regla 7 · Leer un campo sensible deja fila en el registro de accesos.
                DB::table('data_access_logs')->insert([
                    'tenant_id' => TenancyScope::requireTenant(), 'user_id' => $readerUserId, 'access_kind' => 'view', 'data_category' => 'salary',
                    'subject_person_id' => $e->person_id, 'entity_type' => 'employment_compensation', 'entity_id' => $salary->id,
                    'fields' => '{base_salary}', 'purpose' => 'certificate', 'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
                ]);
            } else {
                $lines[] = '';
                $lines[] = (string) trans('certificates.body.no_salary', [], $locale);
            }
        }

        if ($kind === 'vacation_certificate') {
            $periods = DB::table('vacation_requests')->where('employment_id', $employmentId)->whereIn('status', ['approved', 'taken'])
                ->where('starts_on', '<=', $today)->whereNull('deleted_at')->orderByDesc('starts_on')->limit(self::VACATION_PERIODS)
                ->get(['starts_on', 'ends_on', 'days']);
            $lines[] = '';
            if ($periods->isEmpty()) {
                $lines[] = (string) trans('certificates.body.no_vacations', [], $locale);
                $vars['vacation_periods'] = '';
            } else {
                $lines[] = (string) trans('certificates.body.vacations', [], $locale);
                $list = [];
                foreach ($periods as $v) {
                    $list[] = '- '.trans('certificates.body.vacation_period', [
                        'from' => $date((string) $v->starts_on), 'to' => $date((string) $v->ends_on), 'days' => self::trimDays((string) $v->days),
                    ], $locale);
                }
                $lines = array_merge($lines, $list);
                $vars['vacation_periods'] = implode("\n", $list);
            }
        }

        $lines[] = '';
        $lines[] = (string) trans($purpose !== null && $purpose !== '' ? 'certificates.body.closing_purpose' : 'certificates.body.closing', $vars, $locale);
        $lines[] = '';
        $lines[] = (string) trans('certificates.body.place_date', $vars, $locale);

        return [
            'title' => mb_strtoupper((string) trans('certificates.kinds.'.$kind, [], $locale)),
            'body' => implode("\n", $lines),
            'footer' => (string) trans('certificates.body.footer', $vars, $locale),
            'variables' => $vars,
        ];
    }

    /** Monto decimal exacto con separador de miles (sin pasar por float). */
    public static function amount(string $value): string
    {
        $neg = str_starts_with($value, '-');
        [$int, $dec] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
        $grouped = strrev(implode(',', str_split(strrev($int), 3)));

        return ($neg ? '-' : '').$grouped.'.'.str_pad(substr($dec, 0, 2), 2, '0');
    }

    private static function trimDays(string $days): string
    {
        return str_contains($days, '.') ? rtrim(rtrim($days, '0'), '.') : $days;
    }
}
