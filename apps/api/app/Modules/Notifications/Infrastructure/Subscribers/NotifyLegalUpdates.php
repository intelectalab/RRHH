<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * PAR-4 · PAR-7 · RN-PAR-06 · Avisa a Payroll y al Administrador de la organización que se publicó una
 * actualización normativa con su norma y fecha; si toca un periodo de planilla abierto, lo dice para que el
 * responsable lo simule (un periodo cerrado nunca se recalcula: se propone planilla complementaria).
 */
final class NotifyLegalUpdates implements DomainEventSubscriber
{
    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'notifications.legal_updates';
    }

    public function handles(): array
    {
        return [DomainEventTypes::LEGAL_PARAMETERS_PUBLISHED];
    }

    public function handle(OutboxEvent $event): void
    {
        /** @var array{publication_id: string, title: string, legal_source: string, valid_from: string, codes: list<string>} $p */
        $p = $event->payload;
        TenancyScope::runAs($event->tenant_id, function () use ($event, $p): void {
            $roleIds = DB::table('roles')->whereIn('system_role_code', ['payroll', 'admin_empresa'])->whereNull('deleted_at')->pluck('id');
            $users = [];
            foreach ($roleIds as $roleId) {
                $users = [...$users, ...$this->notifier->usersWithRole((string) $roleId)];
            }
            $users = array_values(array_unique($users, SORT_REGULAR));
            $open = DB::table('payroll_periods')->where('ends_on', '>=', $p['valid_from'])->whereNotIn('status', ['closed', 'paid', 'accounted'])->count();
            $this->notifier->notify($open > 0 ? 'payroll.legal_update_open_period' : 'payroll.legal_update', $users, [
                'title' => $p['title'], 'legal_source' => $p['legal_source'], 'valid_from' => $p['valid_from'], 'open_periods' => (string) $open,
            ], 'legal_parameter_publication', $event->aggregate_id);
        });
    }
}
