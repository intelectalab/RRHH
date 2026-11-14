<?php

namespace App\Modules\Contracts\Infrastructure\Home;

use App\Shared\Home\HomeCard;
use App\Shared\Home\HomeContext;
use App\Shared\Home\HomeSql;
use Illuminate\Support\Facades\DB;

/**
 * COL-12 · MSS-1 · Fechas de contrato que exigen una decisión en los próximos días.
 *
 * - contracts_expiring: contratos firmados o vigentes que vencen (valid_to) dentro de la ventana, más los
 *   ya vencidos sin regularizar (CON-4, RN-CON-05).
 * - probation_ending: fin del periodo de prueba dentro de la ventana (trial_period_ends_on del contrato).
 */
final class ContractDatesCard implements HomeCard
{
    public const WINDOW_DAYS = 30;

    private function __construct(private readonly string $kind) {}

    public static function expiring(): self
    {
        return new self('contracts_expiring');
    }

    public static function probation(): self
    {
        return new self('probation_ending');
    }

    public function key(): string
    {
        return $this->kind;
    }

    public function permission(): string
    {
        return 'contracts.contracts.view';
    }

    public function audiences(): array
    {
        return [self::HR, self::MANAGER];
    }

    public function order(): int
    {
        return $this->kind === 'contracts_expiring' ? 50 : 55;
    }

    public function compute(HomeContext $context): array
    {
        $today = $context->today->toDateString();
        $until = $context->today->addDays(self::WINDOW_DAYS)->toDateString();
        $expiring = $this->kind === 'contracts_expiring';
        $column = $expiring ? 'k.valid_to' : 'k.trial_period_ends_on';

        $query = HomeSql::employments()->whereIn('e.status', HomeSql::OPEN)
            ->join('contracts as k', 'k.employment_id', '=', 'e.id')->whereNull('k.deleted_at');
        if ($expiring) {
            $query->where(function ($w) use ($today, $until): void {
                $w->where(fn ($q) => $q->whereIn('k.status', ['signed', 'active'])->whereBetween('k.valid_to', [$today, $until]))
                    ->orWhere('k.status', 'expired');
            });
        } else {
            $query->whereIn('k.status', ['signed', 'active'])->whereBetween('k.trial_period_ends_on', [$today, $until]);
        }
        $context->restrict($query, 'e.id', $this->permission());

        $count = (clone $query)->count();
        $overdue = $expiring ? (clone $query)->where('k.status', 'expired')->count() : 0;
        $rows = $query->orderBy($column)->limit(8)
            ->get(['e.id as employment_id', DB::raw(HomeSql::PERSON_NAME.' as name'), "{$column} as date", 'k.status as detail']);

        return [
            'value' => $count,
            'link' => $expiring ? '/personas/contratos' : $context->link('/personas/contratos', '/mi-equipo'),
            'tone' => $overdue > 0 ? 'danger' : ($count > 0 ? 'warning' : null),
            'items' => HomeSql::items($rows),
            'meta' => ['window_days' => self::WINDOW_DAYS, 'overdue' => $overdue],
        ];
    }
}
