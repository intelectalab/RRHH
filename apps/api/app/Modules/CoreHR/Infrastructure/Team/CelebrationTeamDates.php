<?php

namespace App\Modules\CoreHR\Infrastructure\Team;

use App\Shared\Team\TeamDateSource;
use Illuminate\Support\Facades\DB;

/**
 * MSS-4 · Cumpleaños y aniversarios de ingreso del equipo: la próxima ocurrencia de cada fecha dentro del rango
 * (el 29 de febrero cae el 28 en años no bisiestos). Del cumpleaños solo viaja el día: nunca el año ni la edad.
 * La organización puede ocultar los cumpleaños (celebraciones.mostrar_cumpleanos); eso lo decide quien consulta.
 */
final class CelebrationTeamDates implements TeamDateSource
{
    private function __construct(private readonly string $kind) {}

    public static function birthdays(): self
    {
        return new self('birthday');
    }

    public static function anniversaries(): self
    {
        return new self('anniversary');
    }

    public function key(): string
    {
        return $this->kind;
    }

    public function permission(): string
    {
        return 'people.team.view';
    }

    public function items(array $employmentIds, string $from, string $to): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $source = $this->kind === 'birthday' ? 'p.birth_date' : 'coalesce(e.seniority_date, e.hire_date)';
        $rows = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->whereIn('e.id', $employmentIds)->whereNull('e.deleted_at')->whereRaw("{$source} IS NOT NULL")
            ->get(['e.id', DB::raw("{$source} as since")]);

        $out = [];
        $fromYear = (int) substr($from, 0, 4);
        $toYear = (int) substr($to, 0, 4);
        foreach ($rows as $row) {
            $since = substr((string) $row->since, 0, 10);
            for ($year = $fromYear; $year <= $toYear; $year++) {
                $date = self::occurrence($since, $year);
                if ($date < $from || $date > $to || $date <= $since) {
                    continue;
                }
                $item = ['employment_id' => (string) $row->id, 'date' => $date];
                if ($this->kind === 'anniversary') {
                    $item['years'] = $year - (int) substr($since, 0, 4);
                }
                $out[] = $item;
            }
        }

        return $out;
    }

    /** La fecha de ese año (el 29 de febrero pasa al 28 si el año no es bisiesto). */
    public static function occurrence(string $since, int $year): string
    {
        $month = (int) substr($since, 5, 2);
        $day = (int) substr($since, 8, 2);
        if ($month === 2 && $day === 29 && ! checkdate(2, 29, $year)) {
            $day = 28;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
