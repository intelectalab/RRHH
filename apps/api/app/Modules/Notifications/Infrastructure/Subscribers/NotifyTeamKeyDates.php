<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Labor\MasterData;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * MSS-4 · Recordatorio al jefe del cumpleaños o el aniversario de ingreso de alguien de su equipo. El jefe sale
 * del servicio de datos maestros (HIS-6) a la fecha del aviso. Las preferencias del manager (canales, silencio)
 * las aplica el Notifier. Del cumpleaños solo se menciona el día.
 */
final class NotifyTeamKeyDates implements DomainEventSubscriber
{
    public const BIRTHDAY = 'team.birthday';

    public const ANNIVERSARY = 'team.anniversary';

    public function __construct(private readonly Notifier $notifier, private readonly MasterData $masterData) {}

    public function consumerName(): string
    {
        return 'notifications.notify_team_key_dates';
    }

    public function handles(): array
    {
        return [DomainEventTypes::TEAM_KEY_DATE_UPCOMING];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            $p = $event->payload;
            $employmentId = (string) ($p['employment_id'] ?? '');
            $manager = $this->masterData->managerAt($employmentId, now()->toDateString());
            if ($manager === null) {
                return;
            }
            $managerPerson = DB::table('employments')->where('id', $manager)->value('person_id');
            $user = $managerPerson === null ? null : DB::table('users')->where('person_id', $managerPerson)->where('status', 'active')->whereNull('deleted_at')->value('id');
            $person = DB::table('employments as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $employmentId)
                ->first(['p.first_names', 'p.preferred_name', 'p.paternal_surname']);
            if ($user === null || $person === null) {
                return;
            }
            $employee = trim(($person->preferred_name ?: $person->first_names).' '.$person->paternal_surname);
            $birthday = ($p['kind'] ?? null) === 'birthday';
            $vars = ['employee' => $employee, 'date' => (string) ($p['date'] ?? ''), 'employment_id' => $employmentId];
            if (! $birthday) {
                $vars['years'] = (int) ($p['years'] ?? 0);
            }
            $this->notifier->notify($birthday ? self::BIRTHDAY : self::ANNIVERSARY, [(string) $user], $vars, 'employment', $employmentId);
        });
    }
}
