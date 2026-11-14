<?php

namespace App\Modules\Contracts\Infrastructure\Calendar;

use App\Modules\Contracts\Domain\ContractStateMachine;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Shared\Calendar\CalendarSource;
use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CON-4 · Vencimientos de contratos en el calendario central (CFG-8): el día de fin de cada contrato firmado
 * o vigente, con el mismo alcance que la lista de contratos (regla 6). Los ya renovados con un sucesor firmado
 * no aparecen. El aviso no muestra condiciones del contrato: solo el colaborador y el tipo.
 */
final class ContractCalendarSource implements CalendarSource
{
    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'contracts';
    }

    public function permission(): string
    {
        return 'contracts.contracts.view';
    }

    public function items(CarbonImmutable $from, CarbonImmutable $to, Request $request, ?EffectivePermissions $permissions): array
    {
        $query = Contract::query()->select('contracts.*')
            ->whereIn('contracts.status', [ContractStateMachine::SIGNED, ContractStateMachine::ACTIVE])
            ->whereNotNull('contracts.valid_to')
            ->whereBetween('contracts.valid_to', [$from->toDateString(), $to->toDateString()])
            ->whereNotExists(fn ($q) => $q->from('contracts as s')->whereColumn('s.renewal_of_contract_id', 'contracts.id')
                ->whereNull('s.deleted_at')->whereIn('s.status', [ContractStateMachine::SIGNED, ContractStateMachine::ACTIVE]));
        $this->scope->applyEmployment($query, $request, $this->permission(), 'contracts.employment_id');
        /** @var list<Contract> $rows */
        $rows = $query->orderBy('contracts.valid_to')->limit(500)->get()->all();
        if ($rows === []) {
            return [];
        }
        $people = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')
            ->whereIn('e.id', array_values(array_unique(array_map(fn (Contract $c) => $c->employment_id, $rows))))
            ->get(['e.id', 'p.first_names', 'p.preferred_name', 'p.paternal_surname'])->keyBy('id');
        $types = DB::table('contract_types')->whereIn('id', array_values(array_unique(array_map(fn (Contract $c) => $c->contract_type_id, $rows))))
            ->pluck('name', 'id');

        return array_map(function (Contract $c) use ($people, $types): array {
            $p = $people->get($c->employment_id);
            $ends = (string) $c->valid_to?->toDateString();

            return [
                'id' => $c->id,
                'title' => $p === null ? (string) ($c->number ?? '') : trim(($p->preferred_name ?: $p->first_names).' '.$p->paternal_surname),
                'subtitle' => $types[$c->contract_type_id] ?? null,
                'starts_on' => $ends,
                'ends_on' => $ends,
                'link' => '/personas/colaboradores/'.$c->employment_id.'?tab=contracts',
                'tone' => 'contract_end',
            ];
        }, $rows);
    }
}
