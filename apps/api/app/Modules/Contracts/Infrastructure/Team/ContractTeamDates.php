<?php

namespace App\Modules\Contracts\Infrastructure\Team;

use App\Modules\Contracts\Domain\ContractStateMachine;
use App\Shared\Team\TeamDateSource;
use Illuminate\Support\Facades\DB;

/**
 * MSS-4 · Fechas de contrato del equipo: fin del periodo de prueba y fin del contrato firmado o vigente. Los
 * contratos ya renovados con un sucesor firmado no aparecen. Sin condiciones económicas: solo la fecha.
 */
final class ContractTeamDates implements TeamDateSource
{
    private const RUNNING = [ContractStateMachine::SIGNED, ContractStateMachine::ACTIVE];

    private function __construct(private readonly string $kind) {}

    public static function probation(): self
    {
        return new self('probation_end');
    }

    public static function expiry(): self
    {
        return new self('contract_end');
    }

    public function key(): string
    {
        return $this->kind;
    }

    public function permission(): string
    {
        return 'contracts.contracts.view';
    }

    public function items(array $employmentIds, string $from, string $to): array
    {
        if ($employmentIds === []) {
            return [];
        }
        $column = $this->kind === 'probation_end' ? 'k.trial_period_ends_on' : 'k.valid_to';
        $query = DB::table('contracts as k')->whereIn('k.employment_id', $employmentIds)->whereNull('k.deleted_at')
            ->whereIn('k.status', self::RUNNING)->whereBetween($column, [$from, $to]);
        if ($this->kind === 'contract_end') {
            $query->whereNotExists(fn ($q) => $q->from('contracts as s')->whereColumn('s.renewal_of_contract_id', 'k.id')
                ->whereNull('s.deleted_at')->whereIn('s.status', self::RUNNING));
        }

        $out = [];
        foreach ($query->orderBy($column)->get(['k.employment_id', DB::raw("{$column} as date")]) as $r) {
            $out[] = ['employment_id' => (string) $r->employment_id, 'date' => substr((string) $r->date, 0, 10)];
        }

        return $out;
    }
}
