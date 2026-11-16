<?php

namespace App\Modules\CoreHR\Http\Controllers;

use App\Modules\CoreHR\Application\MyTeam;
use App\Modules\CoreHR\Application\TeamKeyDates;
use App\Shared\Exceptions\InvalidFilterException;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Query\TableQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MSS-2 · GET /my-team: equipo directo e indirecto del manager con acceso a la ficha permitida.
 * MSS-4 · GET /my-team/key-dates: cumpleaños, aniversarios, fin del periodo de prueba y contratos por vencer.
 */
final class MyTeamController
{
    private const FILTERS = ['level'];

    private const KINDS = ['birthday', 'anniversary', 'probation_end', 'contract_end'];

    public function __construct(private readonly MyTeam $team, private readonly TeamKeyDates $dates) {}

    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter');
        if (is_array($filter) && array_diff(array_keys($filter), self::FILTERS) !== []) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
        }
        $level = TableQuery::filter($request, 'level');
        if ($level !== null && ! in_array($level, ['direct', 'indirect'], true)) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), self::FILTERS);
        }
        $permissions = $request->attributes->get('effective_permissions');
        $permissions = $permissions instanceof EffectivePermissions ? $permissions : new EffectivePermissions([], [], []);

        return response()->json($this->team->list(
            (string) $request->user()?->getAuthIdentifier(),
            $permissions,
            now()->toDateString(),
            ['level' => $level, 'q' => is_string($request->query('q')) ? $request->query('q') : null],
        ));
    }

    public function keyDates(Request $request): JsonResponse
    {
        $filter = $request->query('filter');
        if ((is_array($filter) && array_diff(array_keys($filter), ['kind']) !== []) || ($filter !== null && ! is_array($filter))) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['kind']);
        }
        $kinds = array_values(array_filter(explode(',', (string) (TableQuery::filter($request, 'kind') ?? ''))));
        if (array_diff($kinds, self::KINDS) !== []) {
            throw new InvalidFilterException((string) trans('errors.invalid_filter'), ['kind']);
        }
        $today = CarbonImmutable::now()->startOfDay();
        $date = function (string $name, string $default): CarbonImmutable {
            $value = request()->query($name);
            if ($value === null) {
                return CarbonImmutable::parse($default);
            }
            $parsed = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : false;
            if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $value) {
                throw new InvalidFilterException((string) trans('people.team.invalid_range'), ['from', 'to']);
            }

            return $parsed;
        };
        $from = $date('from', $today->toDateString());
        $to = $date('to', $from->addDays(TeamKeyDates::DEFAULT_DAYS)->toDateString());
        if ($to->lt($from) || $from->diffInDays($to) > TeamKeyDates::MAX_DAYS) {
            throw new InvalidFilterException((string) trans('people.team.invalid_range'), ['from', 'to']);
        }
        $permissions = $request->attributes->get('effective_permissions');
        $permissions = $permissions instanceof EffectivePermissions ? $permissions : new EffectivePermissions([], [], []);

        return response()->json($this->dates->list((string) $request->user()?->getAuthIdentifier(), $permissions, $today->toDateString(),
            $from->toDateString(), $to->toDateString(), $kinds, $request->ip()));
    }
}
