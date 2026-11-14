<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * CON-2 · El fin del periodo de prueba se avisa al jefe del colaborador y a RRHH (quien edita contratos),
 * con la anticipación del parámetro contratos.aviso_fin_prueba_dias. CON-4 · RN-CON-04: también el vencimiento
 * próximo (con el acumulado frente al tope) y el tope acumulado cercano. Los avisos no llevan condiciones
 * económicas del contrato: solo el nombre, las fechas, el acumulado en días y el enlace.
 */
final class NotifyContractEvents implements DomainEventSubscriber
{
    public const PROBATION_ENDING = 'contracts.probation_ending';

    public const EXPIRING = 'contracts.expiring';

    public const CAP_APPROACHING = 'contracts.cap_approaching';

    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'notifications.notify_contract_events';
    }

    public function handles(): array
    {
        return [DomainEventTypes::CONTRACT_PROBATION_ENDING, DomainEventTypes::CONTRACT_EXPIRING, DomainEventTypes::CONTRACT_CAP_APPROACHING];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            $p = $event->payload;
            $employmentId = (string) ($p['employment_id'] ?? '');
            $person = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $employmentId)
                ->first(['p.first_names', 'p.preferred_name', 'p.paternal_surname']);
            if ($person === null) {
                return;
            }
            $recipients = array_values(array_unique(array_merge($this->manager($employmentId), $this->usersWith('contracts.contracts.update'))));
            if ($recipients === []) {
                return;
            }
            $employee = trim(($person->preferred_name ?: $person->first_names).' '.$person->paternal_surname);
            [$type, $vars] = match ($event->event_type) {
                DomainEventTypes::CONTRACT_EXPIRING => [self::EXPIRING, [
                    'ends_on' => (string) ($p['ends_on'] ?? ''), 'days_left' => (string) ($p['days_left'] ?? ''), 'explanation' => (string) ($p['explanation'] ?? ''),
                ]],
                DomainEventTypes::CONTRACT_CAP_APPROACHING => [self::CAP_APPROACHING, [
                    'ends_on' => (string) ($p['ends_on'] ?? ''), 'explanation' => (string) ($p['explanation'] ?? ''),
                ]],
                default => [self::PROBATION_ENDING, ['ends_on' => (string) ($p['trial_period_ends_on'] ?? '')]],
            };
            $this->notifier->notify($type, $recipients, ['employee' => $employee, 'employment_id' => $employmentId] + $vars,
                'contract', (string) ($p['contract_id'] ?? ''));
        });
    }

    /**
     * Usuario del jefe: el jefe de la asignación vigente o, si no tiene, quien ocupa la posición jefe.
     *
     * @return list<string>
     */
    private function manager(string $employmentId): array
    {
        $today = now()->toDateString();
        $a = DB::table('employment_assignments')->where('employment_id', $employmentId)->where('valid_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))->first(['manager_employment_id', 'position_id']);
        $managerEmployment = $a?->manager_employment_id;
        if ($managerEmployment === null && $a?->position_id !== null) {
            $boss = DB::table('positions')->where('id', $a->position_id)->value('reports_to_position_id');
            $managerEmployment = $boss === null ? null : DB::table('employment_assignments')->where('position_id', $boss)->where('valid_from', '<=', $today)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $today))->value('employment_id');
        }
        if ($managerEmployment === null) {
            return [];
        }
        $personId = DB::table('employments')->where('id', $managerEmployment)->value('person_id');
        $user = $personId === null ? null : DB::table('users')->where('person_id', $personId)->where('status', 'active')->whereNull('deleted_at')->value('id');

        return $user === null ? [] : [(string) $user];
    }

    /**
     * @return list<string>
     */
    private function usersWith(string $permission): array
    {
        $today = now()->toDateString();

        return array_values(DB::table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')->whereNull('r.deleted_at')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('rp.permission_code', $permission)
            ->where('u.status', 'active')->whereNull('u.deleted_at')
            ->where(fn ($q) => $q->whereNull('ur.valid_from')->orWhere('ur.valid_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ur.valid_to')->orWhere('ur.valid_to', '>=', $today))
            ->distinct()->pluck('u.id')->map(fn ($id): string => (string) $id)->all());
    }
}
