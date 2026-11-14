<?php

namespace App\Modules\Contracts\Application;

use App\Modules\Contracts\Domain\ContractStateMachine;
use App\Modules\Contracts\Infrastructure\Models\Contract;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Settings\ParameterResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CON-4 · RN-CON-04 · Contratos por vencer y tope acumulado.
 *
 * - Vencimiento: con las anticipaciones de `contratos.alerta_vencimiento_dias` (por empresa; p. ej. 60, 30
 *   y 15 días) se avisa al jefe y a RRHH una vez por anticipación y por fecha de fin. Si la fecha de fin
 *   cambia (prórroga), vuelve a avisar.
 * - Tope acumulado: si al terminar el contrato vigente faltan `contratos.alerta_tope_acumulado_dias` o
 *   menos para el plazo máximo de la modalidad (RN-CON-02) o el tope total (RN-CON-03), se avisa una vez.
 *
 * Cada aviso explica cuánto acumula la persona en la empresa y cuál es el tope (ContractTerms). No se avisa
 * de un contrato ya regularizado: con renovación firmada o con el cese de la relación registrado.
 */
final class ContractAlerts
{
    /** Estados que siguen corriendo hacia su fecha de fin. */
    private const RUNNING = [ContractStateMachine::SIGNED, ContractStateMachine::ACTIVE];

    public function __construct(
        private readonly ContractTerms $terms,
        private readonly ParameterResolver $parameters,
        private readonly OutboxWriter $outbox,
    ) {}

    /**
     * Tarea diaria (contracts:apply-due), dentro del contexto de una organización.
     *
     * @return array{expiry_alerts: int, cap_alerts: int}
     */
    public function run(string $today): array
    {
        $counts = ['expiry_alerts' => 0, 'cap_alerts' => 0];
        $contracts = Contract::query()->whereIn('status', self::RUNNING)->whereNotNull('valid_to')
            ->where('valid_to', '>=', $today)->get();
        foreach ($contracts as $contract) {
            DB::transaction(function () use ($contract, $today, &$counts): void {
                $locked = Contract::query()->whereKey($contract->id)->lockForUpdate()->first();
                if ($locked === null || $this->regularized($locked)) {
                    return;
                }
                $expiry = $this->expiryAlert($locked, $today);
                $cap = $this->capAlert($locked, $today);
                $counts['expiry_alerts'] += $expiry ? 1 : 0;
                $counts['cap_alerts'] += $cap ? 1 : 0;
            });
        }

        return $counts;
    }

    /**
     * Contratos que vencen dentro de la ventana elegida y los vencidos sin regularizar (RN-CON-05), con el
     * acumulado frente al tope y la acción sugerida. $query ya trae el alcance del usuario aplicado.
     *
     * @param  Builder<Contract>  $query
     * @return list<array{contract: Contract, days_left: int, accumulation: array<string, mixed>, suggested_action: string, renewal_contract_id: string|null, alert_days: list<int>}>
     */
    public function expiring(Builder $query, string $today, int $within, bool $includeExpired): array
    {
        $until = CarbonImmutable::parse($today)->addDays($within)->toDateString();
        $query->where(function ($q) use ($today, $until, $includeExpired): void {
            $q->where(function ($r) use ($today, $until): void {
                $r->whereIn('contracts.status', self::RUNNING)->whereNotNull('contracts.valid_to')
                    ->whereBetween('contracts.valid_to', [$today, $until]);
            });
            if ($includeExpired) {
                $q->orWhere('contracts.status', ContractStateMachine::EXPIRED);
            }
        });
        /** @var list<Contract> $rows */
        $rows = $query->orderBy('contracts.valid_to')->get()->all();
        $country = $this->country();
        $out = [];
        foreach ($rows as $c) {
            $renewal = DB::table('contracts')->where('renewal_of_contract_id', $c->id)->whereNull('deleted_at')
                ->whereNotIn('status', ['cancelled'])->orderByDesc('created_at')->first(['id', 'status']);
            // Con la renovación ya firmada el contrato quedó regularizado: no se lista como pendiente.
            if ($renewal !== null && in_array($renewal->status, self::RUNNING, true)) {
                continue;
            }
            if ($this->terminating($c)) {
                continue;
            }
            $accumulation = $this->terms->accumulation($c->employment_id, $c->contract_type_id, $c->valid_from->toDateString(),
                $c->valid_to?->toDateString(), $c->id, $country, false);
            $daysLeft = $c->valid_to === null ? 0 : (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($c->valid_to->toDateString()), false);
            $out[] = [
                'contract' => $c,
                'days_left' => $daysLeft,
                'accumulation' => $accumulation,
                'suggested_action' => $this->suggestion($c, $accumulation, $renewal?->id),
                'renewal_contract_id' => $renewal === null ? null : (string) $renewal->id,
                'alert_days' => $this->alertDays($c),
            ];
        }

        return $out;
    }

    /**
     * Anticipaciones de aviso de vencimiento de la empresa del contrato, de la mayor a la menor.
     *
     * @return list<int>
     */
    public function alertDays(Contract $contract): array
    {
        $raw = $this->parameters->value('contratos.alerta_vencimiento_dias', $this->legalEntity($contract));
        $days = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), fn (int $d) => $d >= 0)));
        rsort($days);

        return $days;
    }

    private function expiryAlert(Contract $contract, string $today): bool
    {
        $ends = (string) $contract->valid_to?->toDateString();
        $daysLeft = (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($ends), false);
        // La anticipación más corta que ya se alcanzó (p. ej. faltan 12 días → la de 15).
        $reached = array_values(array_filter($this->alertDays($contract), fn (int $d) => $daysLeft <= $d));
        if ($reached === []) {
            return false;
        }
        $threshold = min($reached);
        $sameEnd = $contract->expiry_alerted_for?->toDateString() === $ends;
        if ($sameEnd && $contract->expiry_alerted_days !== null && $contract->expiry_alerted_days <= $threshold) {
            return false;
        }
        $accumulation = $this->terms->accumulation($contract->employment_id, $contract->contract_type_id, $contract->valid_from->toDateString(),
            $ends, $contract->id, $this->country(), false);
        $contract->expiry_alerted_days = $threshold;
        $contract->expiry_alerted_for = Carbon::parse($ends);
        $contract->save();
        $this->outbox->record(DomainEventTypes::CONTRACT_EXPIRING, 'contract', $contract->id, [
            'contract_id' => $contract->id, 'employment_id' => $contract->employment_id, 'ends_on' => $ends,
            'days_left' => $daysLeft, 'threshold_days' => $threshold, 'explanation' => $accumulation['explanation'],
            'over_limit_if_renewed' => $this->atCap($accumulation),
        ]);

        return true;
    }

    private function capAlert(Contract $contract, string $today): bool
    {
        if ($contract->cap_alerted_on !== null) {
            return false;
        }
        $accumulation = $this->terms->accumulation($contract->employment_id, $contract->contract_type_id, $contract->valid_from->toDateString(),
            $contract->valid_to?->toDateString(), $contract->id, $this->country(), false);
        $margin = max(0, (int) ($this->parameters->value('contratos.alerta_tope_acumulado_dias', $this->legalEntity($contract)) ?? 0));
        $remaining = $this->remaining($accumulation);
        if ($remaining === null || $remaining > $margin) {
            return false;
        }
        $contract->cap_alerted_on = Carbon::parse($today);
        $contract->save();
        $this->outbox->record(DomainEventTypes::CONTRACT_CAP_APPROACHING, 'contract', $contract->id, [
            'contract_id' => $contract->id, 'employment_id' => $contract->employment_id,
            'ends_on' => $contract->valid_to?->toDateString(), 'remaining_days' => $remaining,
            'modality_days' => $accumulation['modality_days'], 'modality_max_days' => $accumulation['modality_max_days'],
            'total_days' => $accumulation['total_days'], 'total_max_days' => $accumulation['total_max_days'],
            'explanation' => $accumulation['explanation'],
        ]);

        return true;
    }

    /**
     * Días que le quedan al acumulado (con este contrato completo) hasta el tope más cercano; null si no
     * hay tope verificado.
     *
     * @param  array<string, mixed>  $acc
     */
    private function remaining(array $acc): ?int
    {
        $left = [];
        if (is_int($acc['modality_max_days'] ?? null)) {
            $left[] = $acc['modality_max_days'] - (int) $acc['modality_days'];
        }
        if (is_int($acc['total_max_days'] ?? null)) {
            $left[] = $acc['total_max_days'] - (int) $acc['total_days'];
        }

        return $left === [] ? null : min($left);
    }

    /**
     * @param  array<string, mixed>  $acc
     */
    private function atCap(array $acc): bool
    {
        $r = $this->remaining($acc);

        return $r !== null && $r <= 0;
    }

    /**
     * Acción sugerida para la lista: regularizar un vencido, continuar una renovación ya en curso, convertir a
     * plazo indeterminado si renovar superaría el tope, o renovar.
     *
     * @param  array<string, mixed>  $acc
     */
    private function suggestion(Contract $c, array $acc, ?string $renewalId): string
    {
        if ($c->status === ContractStateMachine::EXPIRED) {
            return 'regularize';
        }
        if ($renewalId !== null) {
            return 'continue_renewal';
        }

        return $this->atCap($acc) ? 'convert_indefinite' : 'renew';
    }

    private function regularized(Contract $c): bool
    {
        $renewed = DB::table('contracts')->where('renewal_of_contract_id', $c->id)->whereNull('deleted_at')
            ->whereIn('status', self::RUNNING)->exists();

        return $renewed || $this->terminating($c);
    }

    /** El cese de la relación ya está registrado para antes o el mismo día del fin del contrato. */
    private function terminating(Contract $c): bool
    {
        $e = DB::table('employments')->where('id', $c->employment_id)->first(['status', 'termination_date']);
        if ($e === null || in_array($e->status, ['terminated', 'cancelled'], true)) {
            return true;
        }

        return $e->status === 'terminating' && $e->termination_date !== null && $c->valid_to !== null
            && (string) $e->termination_date <= $c->valid_to->toDateString();
    }

    private function legalEntity(Contract $contract): ?string
    {
        $id = DB::table('employments')->where('id', $contract->employment_id)->value('legal_entity_id');

        return $id === null ? null : (string) $id;
    }

    private function country(): string
    {
        return (string) (DB::table('current_tenant')->value('country_code') ?? 'PE');
    }
}
