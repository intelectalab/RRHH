<?php

namespace App\Modules\Notifications\Infrastructure\Subscribers;

use App\Shared\Messaging\MessageSender;
use App\Shared\Notifications\Notifier;
use App\Shared\Outbox\Contracts\DomainEventSubscriber;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * COL-6 · COL-8 · Avisos de la ficha del colaborador.
 *
 * - Un hijo derechohabiente cumplió la mayoría de edad: aviso a RRHH (quien edita derechohabientes) para
 *   revisarlo. El aviso no lleva la fecha de nacimiento.
 * - RN-COL-11: una cuenta de pago se registró o cambió. Se avisa al titular en la aplicación y, por un
 *   canal distinto, con un mensaje corto al celular registrado (si lo hay), para que reconozca el cambio.
 *   Ninguno de los dos lleva el número completo: solo los últimos dígitos.
 */
final class NotifyPeopleChanges implements DomainEventSubscriber
{
    public const MAJORITY = 'people.dependent_majority';

    public const BANK_REQUESTED = 'people.bank_account_requested';

    public const BANK_CHANGED = 'people.bank_account_changed';

    public function __construct(
        private readonly Notifier $notifier,
        private readonly MessageSender $messages,
        private readonly TenantDirectory $tenants,
    ) {}

    public function consumerName(): string
    {
        return 'notifications.notify_people_changes';
    }

    public function handles(): array
    {
        return [DomainEventTypes::DEPENDENT_REACHED_MAJORITY, DomainEventTypes::BANK_ACCOUNT_CHANGE_REQUESTED, DomainEventTypes::BANK_ACCOUNT_CHANGED];
    }

    public function handle(OutboxEvent $event): void
    {
        TenancyScope::runAs($event->tenant_id, function () use ($event): void {
            $p = $event->payload;
            if ($event->event_type === DomainEventTypes::DEPENDENT_REACHED_MAJORITY) {
                $this->majority($p);

                return;
            }
            $this->bankAccount($event, $p);
        });
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function majority(array $p): void
    {
        $personId = (string) ($p['person_id'] ?? '');
        $person = DB::table('people')->where('id', $personId)->first(['first_names', 'paternal_surname']);
        if ($person === null) {
            return;
        }
        $employmentId = (string) ($p['employment_id'] ?? '');
        $this->notifier->notify(self::MAJORITY, $this->usersWith('people.dependents.update'), [
            'employee' => trim($person->first_names.' '.$person->paternal_surname),
            'employment_id' => $employmentId,
        ], 'dependent', (string) ($p['dependent_id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function bankAccount(OutboxEvent $event, array $p): void
    {
        $personId = (string) ($p['person_id'] ?? '');
        $holder = DB::table('users')->where('person_id', $personId)->whereNull('deleted_at')->where('status', 'active')->first(['id', 'locale']);
        $category = $event->event_type === DomainEventTypes::BANK_ACCOUNT_CHANGED ? self::BANK_CHANGED : self::BANK_REQUESTED;
        $vars = ['last4' => (string) ($p['account_number_last4'] ?? ''), 'employment_id' => (string) ($p['employment_id'] ?? '')];
        if ($holder !== null) {
            $this->notifier->notify($category, [(string) $holder->id], $vars, 'bank_account', (string) ($p['bank_account_id'] ?? ''));
        }
        $phone = $this->phone($personId, $holder === null ? null : (string) $holder->id);
        if ($phone === null) {
            return;
        }
        $organization = ($this->tenants->profile($event->tenant_id) ?? ['name' => ''])['name'];
        $this->messages->send(
            'sms',
            $phone,
            $category,
            ['last4' => $vars['last4'], 'organization' => $organization],
            $holder !== null && is_string($holder->locale) ? $holder->locale : (string) config('app.locale', 'es'),
            'notification:'.$category,
            // Un mensaje por evento: reprocesar el evento no envía otro.
            'bank_account:'.$event->id,
        );
    }

    /** Celular del titular: el de su cuenta de acceso o, si no tiene, el personal de la ficha (en formato internacional). */
    private function phone(string $personId, ?string $userId): ?string
    {
        if ($userId !== null) {
            $enc = DB::table('user_identities')->where('user_id', $userId)->where('kind', 'phone')->orderByDesc('is_primary')->value('identifier_enc');
            if (is_string($enc)) {
                return '+'.FieldCipher::decrypt($enc);
            }
        }
        $mobile = DB::table('people')->where('id', $personId)->value('mobile_phone');
        if (is_string($mobile) && str_starts_with(trim($mobile), '+')) {
            return '+'.preg_replace('/\D/', '', $mobile);
        }

        return null;
    }

    /**
     * Usuarios activos con un rol vigente que trae el permiso.
     *
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
