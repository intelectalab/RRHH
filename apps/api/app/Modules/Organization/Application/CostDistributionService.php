<?php

namespace App\Modules\Organization\Application;

use App\Modules\Organization\Domain\CostDistribution;
use App\Modules\Organization\Infrastructure\Models\CostCenter;
use App\Modules\Organization\Infrastructure\Models\EmploymentCostDistribution;
use App\Shared\Audit\AuditLogger;
use App\Shared\Organization\StructureAvailability;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORG-4 · RN-ORG-06 · Distribución de una relación laboral entre centros de costo, con vigencia.
 *
 * Registrar una distribución cierra la vigente el día anterior e inserta otra (patrón HIS-1); las
 * líneas suman exactamente 100 % (validado aquí y, al confirmar, por un disparador de la base).
 * Cada centro debe admitir asignaciones a la fecha (activo, abierto y de la misma empresa o compartido).
 */
final class CostDistributionService
{
    private const TABLE = 'employment_cost_distributions';

    public function __construct(
        private readonly EffectiveDated $validity,
        private readonly StructureAvailability $availability,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<int, array{cost_center_id: string, percentage: mixed}>  $lines
     */
    public function record(\stdClass $employment, string $validFrom, array $lines, ?string $reason): EmploymentCostDistribution
    {
        $parsed = [];
        foreach ($lines as $line) {
            $parsed[] = ['cost_center_id' => $line['cost_center_id'], 'hundredths' => CostDistribution::hundredths($line['percentage'])];
        }
        $errors = CostDistribution::errors($parsed);
        if ($errors !== []) {
            throw ValidationException::withMessages(['lines' => array_map(fn (string $e): string => (string) trans('organization.distribution.'.$e), $errors)]);
        }
        $day = EffectiveDated::day($validFrom);
        if ($day < (string) $employment->hire_date) {
            throw ValidationException::withMessages(['valid_from' => [(string) trans('organization.distribution.before_hire')]]);
        }
        foreach ($parsed as $i => $line) {
            $this->availability->assertCostCenterAssignable($line['cost_center_id'], $day, (string) $employment->legal_entity_id, "lines.{$i}.cost_center_id");
        }

        return DB::transaction(function () use ($employment, $day, $parsed, $reason): EmploymentCostDistribution {
            $before = $this->presentAt((string) $employment->id, $day);
            $id = $this->validity->change(self::TABLE, ['employment_id' => (string) $employment->id, 'tenant_id' => (string) $employment->tenant_id], ['reason' => $reason], $day);
            foreach ($parsed as $line) {
                DB::table('employment_cost_distribution_lines')->insert([
                    'tenant_id' => (string) $employment->tenant_id, 'employment_cost_distribution_id' => $id,
                    'cost_center_id' => $line['cost_center_id'], 'percentage' => CostDistribution::format((int) $line['hundredths']),
                ]);
            }
            /** @var EmploymentCostDistribution $distribution */
            $distribution = EmploymentCostDistribution::query()->findOrFail($id);
            $this->audit->record('employment.cost_distribution_changed', 'employment', (string) $employment->id, $before, $this->present($distribution));

            return $distribution;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(string $employmentId): array
    {
        $rows = [];
        foreach (EmploymentCostDistribution::query()->where('employment_id', $employmentId)->orderByDesc('valid_from')->get() as $d) {
            $rows[] = $this->present($d);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function presentAt(string $employmentId, string $date): ?array
    {
        $d = EmploymentCostDistribution::query()->where('employment_id', $employmentId)->asOf($date)->first();

        return $d instanceof EmploymentCostDistribution ? $this->present($d) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(EmploymentCostDistribution $d): array
    {
        $lines = $d->lines()->orderByDesc('percentage')->get();
        $centers = CostCenter::query()->withTrashed()->whereIn('id', $lines->pluck('cost_center_id'))->get()->keyBy('id');

        return [
            'id' => $d->id,
            'employment_id' => $d->employment_id,
            'valid_from' => $d->valid_from->toDateString(),
            'valid_to' => $d->valid_to?->toDateString(),
            'reason' => $d->reason,
            'lines' => $lines->map(function ($l) use ($centers): array {
                $c = $centers->get($l->cost_center_id);

                return [
                    'cost_center_id' => $l->cost_center_id,
                    'code' => $c instanceof CostCenter ? $c->code : null,
                    'name' => $c instanceof CostCenter ? $c->name : null,
                    'percentage' => CostDistribution::format((int) CostDistribution::hundredths((string) $l->percentage)),
                ];
            })->values()->all(),
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }
}
