<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * SAS-11 · Avisa a los administradores de la organización del estado de cuenta del SaaS: comprobante emitido, por
 * vencer o vencido (con la fecha en que se suspendería). El cobro emite cada aviso una sola vez.
 */
final class NotifyBilling implements DomainEventSubscriber
{
    private const CATEGORIES = ['issued' => 'billing.invoice_issued', 'due_soon' => 'billing.due_soon', 'overdue' => 'billing.overdue'];

    public function __construct(private readonly Notifier $notifier) {}

    public function consumerName(): string
    {
        return 'notifications.billing';
    }

    public function handles(): array
    {
        return [DomainEventTypes::SAAS_BILLING_NOTICE];
    }

    public function handle(OutboxEvent $event): void
    {
        /** @var array{kind: string, number: string, total: string, currency: string, due_on: string|null, suspend_on?: string|null} $p */
        $p = $event->payload;
        $category = self::CATEGORIES[$p['kind']] ?? null;
        if ($category === null) {
            return;
        }
        TenancyScope::runAs($event->tenant_id, function () use ($event, $p, $category): void {
            $roleId = DB::table('roles')->where('system_role_code', 'admin_empresa')->whereNull('deleted_at')->value('id');
            $users = $roleId === null ? [] : $this->notifier->usersWithRole((string) $roleId);
            $this->notifier->notify($category, $users, [
                'number' => $p['number'],
                'total' => $p['currency'].' '.$p['total'],
                'due_on' => (string) ($p['due_on'] ?? ''),
                'suspend_on' => (string) ($p['suspend_on'] ?? ''),
            ], 'saas_invoice', $event->aggregate_id);
        });
    }
}
