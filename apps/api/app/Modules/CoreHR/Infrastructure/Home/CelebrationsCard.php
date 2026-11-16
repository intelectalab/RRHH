<?php

namespace App\Modules\CoreHR\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeSql;
use App\Shared\Settings\ParameterResolver;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · MSS-1 · Fechas para celebrar en los próximos días.
 *
 * - birthdays: cumpleaños. La fecha de nacimiento es un dato personal (regla 6): la tarjeta exige
 *   people.people.personal_data.view, muestra solo el día del próximo cumpleaños (nunca el año ni la edad)
 *   y deja la lectura en el registro de accesos (regla 7).
 * - anniversaries: aniversarios de ingreso (antigüedad reconocida o fecha de ingreso), con los años que
 *   se cumplen. No es un dato sensible.
 *
 * MSS-4 · Con celebraciones.mostrar_cumpleanos = no, la tarjeta de cumpleaños viaja vacía y marcada oculta.
 */
final class CelebrationsCard implements HomeCard
{
    public const WINDOW_DAYS = 30;

    private function __construct(private readonly string $kind) {}

    public static function birthdays(): self
    {
        return new self('birthdays');
    }

    public static function anniversaries(): self
    {
        return new self('anniversaries');
    }

    public function key(): string
    {
        return $this->kind;
    }

    public function permission(): string
    {
        return $this->kind === 'birthdays' ? 'people.people.personal_data.view' : 'people.employments.view';
    }

    public function audiences(): array
    {
        return [self::HR, self::MANAGER];
    }

    public function order(): int
    {
        return $this->kind === 'birthdays' ? 90 : 95;
    }

    public function compute(HomeContext $context): array
    {
        if ($this->kind === 'birthdays' && ! (bool) (app(ParameterResolver::class)->value('celebraciones.mostrar_cumpleanos') ?? true)) {
            return ['value' => 0, 'link' => $context->link('/calendario', '/mi-equipo'), 'items' => [], 'meta' => ['window_days' => self::WINDOW_DAYS, 'hidden' => true]];
        }
        $today = $context->today->toDateString();
        $until = $context->today->addDays(self::WINDOW_DAYS)->toDateString();
        $source = $this->kind === 'birthdays' ? 'p.birth_date' : 'coalesce(e.seniority_date, e.hire_date)';
        // Próxima ocurrencia de la fecha (este año o el siguiente); el 29 de febrero cae el 28 en años no bisiestos.
        $years = "(date_part('year', ?::date) - date_part('year', {$source}))::int";
        $next = "CASE WHEN ({$source} + make_interval(years => {$years}))::date < ?::date "
            ."THEN ({$source} + make_interval(years => {$years} + 1))::date ELSE ({$source} + make_interval(years => {$years}))::date END";

        $inner = HomeSql::employments()->whereIn('e.status', HomeSql::OPEN)->whereRaw("{$source} IS NOT NULL")
            ->select(['e.id as employment_id', DB::raw(HomeSql::PERSON_NAME.' as name'), DB::raw("{$next} as next_on"), DB::raw("{$source} as since")])
            ->addBinding([$today, $today, $today, $today], 'select');
        $context->restrict($inner, 'e.id', $this->permission());

        $query = DB::query()->fromSub($inner, 'c')->whereBetween('c.next_on', [$today, $until]);
        if ($this->kind === 'anniversaries') {
            $query->whereRaw('c.since < c.next_on');
        }
        $count = (clone $query)->count();
        $rows = $query->orderBy('c.next_on')->orderBy('c.name')->limit(8)->get();

        $items = [];
        foreach ($rows as $row) {
            $item = ['employment_id' => (string) $row->employment_id, 'name' => (string) $row->name, 'date' => substr((string) $row->next_on, 0, 10), 'link' => '/personas/colaboradores/'.$row->employment_id];
            if ($this->kind === 'anniversaries') {
                $item['years'] = (int) substr((string) $row->next_on, 0, 4) - (int) substr((string) $row->since, 0, 4);
            }
            $items[] = $item;
        }
        if ($this->kind === 'birthdays') {
            $context->logSensitiveRead('other', ['birth_date'], $this->kind);
        }

        return [
            'value' => $count,
            'link' => $context->link('/calendario', '/mi-equipo'),
            'items' => $items,
            'meta' => ['window_days' => self::WINDOW_DAYS],
        ];
    }
}
