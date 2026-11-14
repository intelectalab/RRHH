<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * AUS-3 · Un descanso aprobado alcanzó el umbral a cargo del empleador (o es maternidad): se abre el
 * expediente de subsidio y se avisa a quien gestiona subsidios (time.absences.manage_subsidy). El aviso no
 * lleva diagnóstico ni tipo de descanso: solo el nombre, los días de subsidio, desde cuándo y el enlace.
 */
final class NotifyAbsenceSubsidy implements DomainEventSubscriber
{
    public const SUBSIDY_STARTED = 'absences.subsidy_started';

    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'notifications.notify_absence_subsidy';
    }

    public function handles(): array
    {
        return [DomainEventTypes::ABSENCE_SUBSIDY_STARTED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            $p = $event->payload;
            $person = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', (string) ($p['employment_id'] ?? ''))
                ->first(['p.first_names', 'p.preferred_name', 'p.paternal_surname']);
            if ($person === null) {
                return;
            }
            $recipients = $this->usersWith('time.absences.manage_subsidy');
            if ($recipients === []) {
                return;
            }
            $this->notifier->notify(self::SUBSIDY_STARTED, $recipients, [
                'employee' => trim(($person->preferred_name ?: $person->first_names).' '.$person->paternal_surname),
                'days' => (string) ($p['subsidized_days'] ?? ''),
                'starts_on' => (string) ($p['starts_on'] ?? ''),
            ], 'absence_subsidy_case', (string) ($p['subsidy_case_id'] ?? ''));
        });
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
