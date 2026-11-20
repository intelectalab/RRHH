<?php

namespace App\Modules\Platform\Application\Billing;

use App\Modules\Platform\Application\Console\ConsoleAudit;
use App\Modules\Platform\Application\PlatformSettings;
use App\Modules\Platform\Application\Subscriptions\SubscriptionService;
use App\Modules\Platform\Domain\SaasInvoiceStateMachine;
use App\Modules\Platform\Domain\SubscriptionStateMachine;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Exceptions\AyniException;
use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\LegalParameters\LegalParameters;
use App\Shared\Outbox\Contracts\OutboxWriter;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * SAS-11 · ADR 0010 · Cobro de la suscripción: cargos del periodo, comprobante electrónico, pagos, estado de cuenta y
 * mora.
 *
 * - El cargo nace en borrador con los precios guardados en la suscripción (o en su plan) y en sus complementos; aquí
 *   no hay ningún importe.
 * - Emitir calcula el impuesto con el parámetro legal vigente (igv_tasa_pct) y envía el comprobante al proveedor OSE o
 *   PSE por el outbox: solo queda «emitido» con la constancia de aceptación; un rechazo lo deja «observado».
 * - Los pagos se registran a mano o llegan de la pasarela (adaptador de cobro); al cubrir el total queda pagado y, si
 *   no queda nada vencido, la suscripción se regulariza.
 * - La tarea diaria avisa antes del vencimiento, marca lo vencido y suspende tras los días de mora configurados.
 */
final class SaasBilling
{
    public function __construct(
        private readonly SaasInvoiceStateMachine $machine,
        private readonly SubscriptionService $subscriptions,
        private readonly PlatformSettings $settings,
        private readonly ConsoleAudit $audit,
        private readonly OutboxWriter $outbox,
        private readonly IntegrationManager $integrations,
        private readonly LegalParameters $legal,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(int $page, int $perPage, ?string $tenantId, ?string $status, ?string $einvoiceStatus): array
    {
        $query = DB::table('saas_invoices AS i')
            ->when($tenantId !== null && $tenantId !== '', fn ($q) => $q->where('i.tenant_id', $tenantId))
            ->when($status !== null && $status !== '', fn ($q) => $q->where('i.status', $status))
            ->when($einvoiceStatus !== null && $einvoiceStatus !== '', fn ($q) => $q->where('i.einvoice_status', $einvoiceStatus));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('i.created_at')->orderByDesc('i.number')->forPage($page, $perPage)->get(['i.*']);

        return [
            'data' => array_values($rows->map(fn ($r): array => $this->present($r, false))->all()),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        return $this->present($this->find($id), true);
    }

    /**
     * Cargo del periodo siguiente de la suscripción (o del que empieza en $periodStart), en borrador.
     *
     * @return array<string, mixed>
     */
    public function generate(string $subscriptionId, ?string $periodStart, PlatformUser $actor): array
    {
        $sub = $this->subscriptions->find($subscriptionId);
        $plan = DB::table('plans')->where('id', $sub->plan_id)->first(['name', 'price', 'currency']);
        $price = $sub->price ?? $plan?->price;
        $currency = $sub->currency ?? $plan?->currency;
        if ($price === null) {
            throw new BusinessRuleViolatedException('SAS-11', (string) trans('platform.billing.no_price'));
        }
        if ($currency === null) {
            throw new BusinessRuleViolatedException('SAS-11', (string) trans('platform.billing.no_currency'));
        }
        $currency = (string) $currency;

        $start = $periodStart !== null ? Carbon::parse($periodStart)->startOfDay() : $this->nextPeriodStart($sub);
        $end = ((string) $sub->billing_cycle === 'annual' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow())->subDay();
        $overlap = DB::table('saas_invoices')->where('subscription_id', $sub->id)->where('status', '!=', 'void')
            ->where('period_start', '<=', $end->toDateString())->where('period_end', '>=', $start->toDateString())->exists();
        if ($overlap) {
            throw new AyniException('duplicate', 409, (string) trans('platform.billing.period_invoiced'));
        }

        $lines = [[
            'description' => (string) trans('platform.billing.plan_line', ['plan' => (string) $plan?->name, 'from' => $start->toDateString(), 'to' => $end->toDateString()]),
            'quantity' => '1', 'unit_price' => (string) $price,
        ]];
        $addons = DB::table('subscription_addons AS sa')->join('addons AS a', 'a.id', '=', 'sa.addon_id')
            ->where('sa.subscription_id', $sub->id)->where('sa.starts_on', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('sa.ends_on')->orWhere('sa.ends_on', '>', $start->toDateString()))
            ->get(['a.name', 'a.billing_cycle', 'a.price AS addon_price', 'a.currency AS addon_currency', 'sa.price', 'sa.currency', 'sa.quantity', 'sa.starts_on']);
        foreach ($addons as $a) {
            $addonPrice = $a->price ?? $a->addon_price;
            $addonCurrency = $a->currency ?? $a->addon_currency ?? $currency;
            // Un complemento de pago único se cobra en el periodo en que empieza; uno en otra moneda no entra en este cargo.
            if ($addonPrice === null || (string) $addonCurrency !== $currency
                || ((string) $a->billing_cycle === 'one_time' && (string) $a->starts_on < $start->toDateString())) {
                continue;
            }
            $lines[] = ['description' => (string) trans('platform.billing.addon_line', ['addon' => (string) $a->name]), 'quantity' => (string) $a->quantity, 'unit_price' => (string) $addonPrice];
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $sub, $start, $end, $currency, $lines, $actor): void {
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('saas_invoice_number'))");
            $number = $this->nextNumber((int) $start->format('Y'));
            DB::table('saas_invoices')->insert([
                'id' => $id, 'tenant_id' => $sub->tenant_id, 'subscription_id' => $sub->id, 'number' => $number, 'status' => 'draft',
                'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'subtotal' => 0, 'tax' => 0, 'total' => 0, 'currency' => $currency, 'created_by_platform_user_id' => $actor->id,
            ]);
            foreach ($lines as $line) {
                DB::insert('INSERT INTO saas_invoice_lines (saas_invoice_id, description, quantity, unit_price, amount, currency)
                    VALUES (?, ?, ?::numeric, ?::numeric, round(?::numeric * ?::numeric, 2), ?)',
                    [$id, $line['description'], $line['quantity'], $line['unit_price'], $line['quantity'], $line['unit_price'], $currency]);
            }
            DB::update('UPDATE saas_invoices SET subtotal = s.v, total = s.v FROM (SELECT coalesce(sum(amount), 0) AS v FROM saas_invoice_lines WHERE saas_invoice_id = ?) s WHERE id = ?', [$id, $id]);
            $this->audit->record('billing.invoice_generated', $actor, 'saas_invoice', $id, null, $this->snapshot($id), (string) $sub->tenant_id);
        });

        return $this->show($id);
    }

    /**
     * Envía el comprobante al proveedor de facturación electrónica: calcula el impuesto con el parámetro vigente y
     * deja el envío en el outbox, en la misma transacción.
     *
     * @return array<string, mixed>
     */
    public function issue(string $id, PlatformUser $actor): array
    {
        $invoice = $this->find($id);
        $this->machine->getNextState((string) $invoice->status, SaasInvoiceStateMachine::ISSUE);
        if ((string) $invoice->einvoice_status === 'pending') {
            throw new AyniException('locked', 409);
        }
        $tenant = DB::table('tenants')->where('id', $invoice->tenant_id)->first(['country_code', 'name']);
        $country = (string) $tenant?->country_code;
        [$treatment, $rate] = $this->taxFor($country);
        $buyer = $this->buyer((string) $invoice->tenant_id);
        $before = $this->snapshot($id);

        DB::transaction(function () use ($invoice, $treatment, $rate, $buyer, $actor, $before): void {
            DB::update('UPDATE saas_invoices SET tax_treatment = ?, tax_rate = ?::numeric,
                    tax = CASE WHEN ?::numeric IS NULL THEN 0 ELSE round(subtotal * ?::numeric / 100, 2) END,
                    total = subtotal + CASE WHEN ?::numeric IS NULL THEN 0 ELSE round(subtotal * ?::numeric / 100, 2) END,
                    buyer_tax_id = ?, buyer_name = ?, einvoice_status = ?, einvoice_message = NULL, einvoice_attempts = einvoice_attempts + 1
                WHERE id = ?', [$treatment, $rate, $rate, $rate, $rate, $rate, $buyer['tax_id'], $buyer['name'], 'pending', $invoice->id]);
            $event = TenancyScope::runAs((string) $invoice->tenant_id, fn () => $this->outbox->record(
                DomainEventTypes::SAAS_INVOICE_ISSUE_REQUESTED, 'saas_invoice', (string) $invoice->id, ['invoice_id' => (string) $invoice->id]));
            $this->audit->record('billing.invoice_issue_requested', $actor, 'saas_invoice', (string) $invoice->id, $before, $this->snapshot((string) $invoice->id), (string) $invoice->tenant_id);
            $this->outbox->deliverSoon($event);
        });

        return $this->show($id);
    }

    /**
     * Consumidor del outbox: entrega el comprobante al proveedor y registra la constancia o el rechazo. Solo actúa
     * sobre un envío pendiente: una entrega repetida del evento no emite otro comprobante.
     */
    public function deliver(string $id): void
    {
        $invoice = DB::table('saas_invoices')->where('id', $id)->first();
        if ($invoice === null || (string) $invoice->einvoice_status !== 'pending') {
            return;
        }
        $tenant = DB::table('tenants')->where('id', $invoice->tenant_id)->first(['country_code']);
        $lines = DB::table('saas_invoice_lines')->where('saas_invoice_id', $id)->orderBy('created_at')->orderBy('id')
            ->get(['description', 'quantity', 'unit_price', 'amount']);
        $result = $this->integrations->einvoicing()->issue([
            'key' => (string) $invoice->id,
            'series' => (string) $this->settings->value('billing.einvoice_series'),
            'issue_date' => now()->toDateString(),
            'currency' => (string) $invoice->currency,
            'buyer_tax_id' => $invoice->buyer_tax_id === null ? null : (string) $invoice->buyer_tax_id,
            'buyer_name' => (string) $invoice->buyer_name,
            'buyer_country' => (string) $tenant?->country_code,
            'subtotal' => (string) $invoice->subtotal, 'tax' => (string) $invoice->tax, 'total' => (string) $invoice->total,
            'tax_rate' => $invoice->tax_rate === null ? null : (string) $invoice->tax_rate,
            'lines' => array_values($lines->map(fn ($l): array => [
                'description' => (string) $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price, 'amount' => (string) $l->amount,
            ])->all()),
        ]);

        DB::transaction(function () use ($invoice, $result): void {
            $before = $this->snapshot((string) $invoice->id);
            if ($result['accepted']) {
                $to = $this->machine->getNextState((string) $invoice->status, SaasInvoiceStateMachine::ACCEPT);
                $dueOn = now()->addDays(max(0, (int) $this->settings->value('billing.due_days')))->toDateString();
                DB::table('saas_invoices')->where('id', $invoice->id)->update([
                    'status' => $to, 'issued_at' => now(), 'due_on' => $dueOn, 'einvoice_status' => 'accepted',
                    'einvoice_series' => $result['series'], 'einvoice_number' => $result['number'], 'einvoice_ticket' => $result['ticket'],
                    'einvoice_hash' => $result['hash'], 'einvoice_message' => $result['message'], 'einvoice_accepted_at' => now(),
                ]);
                $this->notice((string) $invoice->id, 'issued');
            } else {
                $to = $this->machine->getNextState((string) $invoice->status, SaasInvoiceStateMachine::REJECT);
                DB::table('saas_invoices')->where('id', $invoice->id)->update([
                    'status' => $to, 'einvoice_status' => 'rejected', 'einvoice_ticket' => $result['ticket'], 'einvoice_message' => $result['message'],
                ]);
            }
            $this->audit->record($result['accepted'] ? 'billing.einvoice_accepted' : 'billing.einvoice_rejected', null, 'saas_invoice',
                (string) $invoice->id, $before, $this->snapshot((string) $invoice->id), (string) $invoice->tenant_id);
        });
    }

    /**
     * Pago registrado a mano o confirmado por la pasarela (gateway_ref).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function recordPayment(string $id, array $input, PlatformUser $actor): array
    {
        $invoice = $this->find($id);
        if (! in_array((string) $invoice->status, ['issued', 'overdue'], true)) {
            throw new AyniException('invalid_transition', 409);
        }
        $gatewayRef = isset($input['gateway_ref']) && is_string($input['gateway_ref']) && $input['gateway_ref'] !== '' ? $input['gateway_ref'] : null;
        if ($gatewayRef !== null) {
            if (DB::table('saas_payments')->where('gateway_ref', $gatewayRef)->where('status', 'confirmed')->exists()) {
                throw new AyniException('duplicate', 409, (string) trans('platform.billing.payment_duplicated'));
            }
            $verified = $this->integrations->payments()->verify($gatewayRef);
            if (! $verified['confirmed']) {
                throw new BusinessRuleViolatedException('SAS-11', (string) trans('platform.billing.payment_not_confirmed'));
            }
            $payment = ['amount' => $verified['amount'], 'currency' => $verified['currency'], 'paid_on' => $verified['paid_on'],
                'method' => in_array($verified['method'], ['transfer', 'card', 'wallet', 'cash', 'other'], true) ? $verified['method'] : 'other',
                'reference' => $verified['reference'], 'source' => 'gateway', 'gateway_ref' => $gatewayRef];
        } else {
            /** @var array{amount: array{amount: string, currency: string}, paid_on: string, method?: string, reference?: string|null} $input */
            $payment = ['amount' => (string) $input['amount']['amount'], 'currency' => (string) $input['amount']['currency'], 'paid_on' => (string) $input['paid_on'],
                'method' => (string) ($input['method'] ?? 'transfer'), 'reference' => $input['reference'] ?? null, 'source' => 'manual', 'gateway_ref' => null];
        }
        if ($payment['currency'] !== (string) $invoice->currency) {
            throw ValidationException::withMessages(['amount' => [(string) trans('platform.billing.currency_mismatch')]]);
        }
        $exceeds = DB::selectOne('SELECT (?::numeric <= 0 OR ?::numeric > total - amount_paid) AS bad FROM saas_invoices WHERE id = ?', [$payment['amount'], $payment['amount'], $invoice->id]);
        if ($exceeds !== null && (bool) $exceeds->bad) {
            throw new BusinessRuleViolatedException('SAS-11', (string) trans('platform.billing.payment_exceeds'));
        }

        $paymentId = (string) Str::uuid();
        DB::transaction(function () use ($invoice, $payment, $paymentId, $actor): void {
            $before = $this->snapshot((string) $invoice->id);
            DB::table('saas_payments')->insert($payment + ['id' => $paymentId, 'tenant_id' => $invoice->tenant_id, 'saas_invoice_id' => $invoice->id,
                'recorded_by_platform_user_id' => $actor->id]);
            DB::update('UPDATE saas_invoices SET amount_paid = amount_paid + ?::numeric WHERE id = ?', [$payment['amount'], $invoice->id]);
            $row = DB::table('saas_invoices')->where('id', $invoice->id)->first(['status', 'total', 'amount_paid']);
            $settled = DB::selectOne('SELECT ?::numeric >= ?::numeric AS done', [(string) $row?->amount_paid, (string) $row?->total]);
            if ($row !== null && $settled !== null && (bool) $settled->done) {
                DB::table('saas_invoices')->where('id', $invoice->id)->update([
                    'status' => $this->machine->getNextState((string) $row->status, SaasInvoiceStateMachine::SETTLE), 'paid_at' => now(),
                ]);
            }
            $this->audit->record('billing.payment_recorded', $actor, 'saas_invoice', (string) $invoice->id, $before,
                $this->snapshot((string) $invoice->id) + ['payment_id' => $paymentId, 'source' => $payment['source']], (string) $invoice->tenant_id);
        });
        $this->regularize((string) $invoice->tenant_id, $actor);

        return $this->show($id);
    }

    /**
     * Anula un borrador o un comprobante observado (nunca uno aceptado por la administración tributaria).
     *
     * @return array<string, mixed>
     */
    public function void(string $id, ?string $reason, PlatformUser $actor): array
    {
        $invoice = $this->find($id);
        $to = $this->machine->getNextState((string) $invoice->status, SaasInvoiceStateMachine::VOID);
        if ((string) $invoice->einvoice_status === 'pending') {
            throw new AyniException('locked', 409);
        }
        $reason = $reason === null ? '' : trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => [(string) trans('platform.billing.reason_required')]]);
        }
        DB::transaction(function () use ($invoice, $to, $reason, $actor): void {
            $before = $this->snapshot((string) $invoice->id);
            DB::table('saas_invoices')->where('id', $invoice->id)->update(['status' => $to, 'voided_at' => now(), 'void_reason' => $reason]);
            $this->audit->record('billing.invoice_voided', $actor, 'saas_invoice', (string) $invoice->id, $before, $this->snapshot((string) $invoice->id), (string) $invoice->tenant_id);
        });

        return $this->show($id);
    }

    /**
     * Estado de cuenta de una organización para la consola.
     *
     * @return array<string, mixed>
     */
    public function statementFor(string $tenantId): array
    {
        $sub = $this->subscriptions->forTenant($tenantId);
        $invoices = DB::table('saas_invoices')->where('tenant_id', $tenantId)->where('status', '!=', 'draft')
            ->orderByDesc('period_start')->orderByDesc('number')->get();
        $payments = DB::table('saas_payments')->where('tenant_id', $tenantId)->orderByDesc('paid_on')->get();

        return $this->statement(
            $sub === null ? null : $this->subscriptionSummary($sub),
            array_values($invoices->map(fn ($r): array => $this->present($r, false))->all()),
            array_values($payments->map(fn ($p): array => $this->presentPayment($p))->all()),
            fn (string $key): mixed => $this->settings->value($key),
        );
    }

    /**
     * Estado de cuenta de la organización activa (lado de la organización): sale de current_tenant_billing(), que
     * solo devuelve lo suyo y nunca los borradores.
     *
     * @return array<string, mixed>
     */
    public function statementForCurrentTenant(): array
    {
        TenancyScope::requireTenant();
        $raw = DB::selectOne('SELECT current_tenant_billing()::text AS j');
        /** @var array{subscription: array<string, mixed>|null, invoices: list<array<string, mixed>>, payments: list<array<string, mixed>>, settings: array<string, mixed>} $data */
        $data = json_decode((string) $raw?->j, true, 512, JSON_THROW_ON_ERROR);
        $sub = $data['subscription'];
        $subscription = $sub === null ? null : [
            'plan' => (string) $sub['plan'], 'status' => (string) $sub['status'], 'billing_cycle' => (string) $sub['billing_cycle'],
            'starts_on' => (string) $sub['starts_on'], 'trial_ends_on' => $sub['trial_ends_on'],
            ...($sub['price'] === null || $sub['currency'] === null ? [] : ['price' => ['amount' => (string) $sub['price'], 'currency' => (string) $sub['currency']]]),
        ];
        $invoices = array_map(fn (array $i): array => $this->presentFromArray($i), $data['invoices']);
        $payments = array_map(fn (array $p): array => [
            'id' => (string) $p['id'], 'invoice_id' => (string) $p['invoice_id'],
            'amount' => ['amount' => (string) $p['amount'], 'currency' => (string) $p['currency']],
            'paid_on' => (string) $p['paid_on'], 'method' => (string) $p['method'], 'status' => (string) $p['status'],
        ], $data['payments']);

        // La regla de mora vigente: la fila de la consola si existe; si no, el valor por defecto de la configuración.
        $settings = $data['settings'];

        return $this->statement($subscription, $invoices, $payments,
            fn (string $key): mixed => array_key_exists($key, $settings) ? $settings[$key] : config('platform.settings.'.$key));
    }

    /**
     * Tarea diaria: aviso antes del vencimiento, comprobantes vencidos y suspensión por mora (regla de la consola).
     *
     * @return array{reminded: int, overdue: int, suspended: int}
     */
    public function runDunning(): array
    {
        $today = now()->startOfDay();
        $reminderDays = max(0, (int) $this->settings->value('billing.reminder_days_before_due'));
        $reminded = 0;
        foreach (DB::table('saas_invoices')->where('status', 'issued')->whereNull('reminded_at')->whereNotNull('due_on')
            ->where('due_on', '>=', $today->toDateString())->where('due_on', '<=', $today->copy()->addDays($reminderDays)->toDateString())->pluck('id') as $id) {
            DB::transaction(function () use ($id): void {
                DB::table('saas_invoices')->where('id', $id)->update(['reminded_at' => now()]);
                $this->notice((string) $id, 'due_soon');
            });
            $reminded++;
        }

        $overdue = 0;
        foreach (DB::table('saas_invoices')->where('status', 'issued')->whereNotNull('due_on')->where('due_on', '<', $today->toDateString())->get() as $inv) {
            DB::transaction(function () use ($inv): void {
                $before = $this->snapshot((string) $inv->id);
                DB::table('saas_invoices')->where('id', $inv->id)->update([
                    'status' => $this->machine->getNextState((string) $inv->status, SaasInvoiceStateMachine::EXPIRE), 'overdue_at' => now(),
                ]);
                $this->audit->record('billing.invoice_overdue', null, 'saas_invoice', (string) $inv->id, $before, $this->snapshot((string) $inv->id), (string) $inv->tenant_id);
                $this->notice((string) $inv->id, 'overdue');
            });
            $sub = $this->subscriptions->forTenant((string) $inv->tenant_id);
            if ($sub !== null && (string) $sub->status === 'active') {
                $this->subscriptions->transition((string) $sub->id, SubscriptionStateMachine::MARK_PAST_DUE, null, null);
            }
            $overdue++;
        }

        $suspended = 0;
        $suspendAfter = (int) $this->settings->value('billing.suspend_after_overdue_days');
        if ($suspendAfter > 0) {
            $limit = $today->copy()->subDays($suspendAfter)->toDateString();
            $tenants = DB::table('saas_invoices')->where('status', 'overdue')->where('due_on', '<', $limit)->distinct()->pluck('tenant_id');
            foreach ($tenants as $tenantId) {
                $sub = $this->subscriptions->forTenant((string) $tenantId);
                if ($sub === null || ! in_array((string) $sub->status, ['active', 'past_due'], true)) {
                    continue;
                }
                $this->subscriptions->transition((string) $sub->id, SubscriptionStateMachine::SUSPEND, (string) trans('platform.billing.overdue_suspension'), null);
                $this->audit->record('billing.suspended_for_overdue', null, 'subscription', (string) $sub->id, null, ['status' => 'suspended'], (string) $tenantId);
                $suspended++;
            }
        }

        return ['reminded' => $reminded, 'overdue' => $overdue, 'suspended' => $suspended];
    }

    public function find(string $id): \stdClass
    {
        $row = TenancyScope::isValidUuid($id) ? DB::table('saas_invoices')->where('id', $id)->first() : null;
        if (! $row instanceof \stdClass) {
            throw new AyniException('not_found', 404);
        }

        return $row;
    }

    /**
     * Sin nada vencido, la suscripción en mora se regulariza; si la suspendió el cobro, se reactiva sin pérdida.
     */
    private function regularize(string $tenantId, PlatformUser $actor): void
    {
        if (DB::table('saas_invoices')->where('tenant_id', $tenantId)->where('status', 'overdue')->exists()) {
            return;
        }
        $sub = $this->subscriptions->forTenant($tenantId);
        if ($sub === null) {
            return;
        }
        if ((string) $sub->status === 'past_due') {
            $this->subscriptions->transition((string) $sub->id, SubscriptionStateMachine::CONVERT, null, $actor);

            return;
        }
        if ((string) $sub->status === 'suspended' && $this->suspendedByBilling((string) $sub->id)) {
            $this->subscriptions->transition((string) $sub->id, SubscriptionStateMachine::REACTIVATE, null, $actor);
        }
    }

    private function suspendedByBilling(string $subscriptionId): bool
    {
        $last = DB::table('platform_audit_events')->where('entity_type', 'subscription')->where('entity_id', $subscriptionId)
            ->whereIn('action', ['billing.suspended_for_overdue', 'subscription.suspend', 'subscription.reactivate'])
            ->orderByDesc('occurred_at')->orderByRaw("CASE action WHEN 'billing.suspended_for_overdue' THEN 0 ELSE 1 END")->value('action');

        return $last === 'billing.suspended_for_overdue';
    }

    /**
     * Tratamiento del impuesto y tasa vigente: en el Perú, el parámetro legal igv_tasa_pct (por validar hasta que lo
     * confirme el contador); fuera, lo que diga la configuración global (pendiente hasta confirmarlo).
     *
     * @return array{0: string, 1: string|null}
     */
    private function taxFor(string $country): array
    {
        $treatment = 'taxed';
        if ($country !== 'PE') {
            $treatment = (string) $this->settings->value('billing.foreign_tax_treatment');
            if ($treatment === 'export_exempt') {
                return ['export_exempt', null];
            }
            if ($treatment !== 'taxed') {
                throw new BusinessRuleViolatedException('ADR-0010', (string) trans('platform.billing.foreign_tax_pending'));
            }
        }
        $param = $this->legal->at('PE', 'igv_tasa_pct', now());
        if ($param === null || $param['pending_validation'] || ! is_string($param['value'])) {
            throw new BusinessRuleViolatedException('ADR-0010', (string) trans('platform.billing.tax_rate_pending'));
        }

        return [$treatment, $param['value']];
    }

    /**
     * Adquiriente: la primera empresa activa de la organización con su número de identificación tributaria.
     *
     * @return array{tax_id: string, name: string}
     */
    private function buyer(string $tenantId): array
    {
        $entity = TenancyScope::runAs($tenantId, fn () => DB::table('legal_entities')->whereNull('deleted_at')->where('status', 'active')
            ->whereNotNull('tax_id')->orderBy('created_at')->first(['tax_id', 'legal_name']));
        if ($entity === null) {
            throw new BusinessRuleViolatedException('SAS-11', (string) trans('platform.billing.buyer_missing'));
        }

        return ['tax_id' => (string) $entity->tax_id, 'name' => (string) $entity->legal_name];
    }

    private function nextPeriodStart(\stdClass $sub): Carbon
    {
        $last = DB::table('saas_invoices')->where('subscription_id', $sub->id)->where('status', '!=', 'void')->max('period_end');
        if (is_string($last)) {
            return Carbon::parse($last)->addDay()->startOfDay();
        }
        $start = Carbon::parse((string) $sub->starts_on)->startOfDay();
        if ($sub->trial_ends_on !== null && Carbon::parse((string) $sub->trial_ends_on)->gte($start)) {
            $start = Carbon::parse((string) $sub->trial_ends_on)->addDay()->startOfDay();
        }

        return $start;
    }

    private function nextNumber(int $year): string
    {
        $prefix = 'GHR-'.$year.'-';
        $last = DB::table('saas_invoices')->where('number', 'like', $prefix.'%')->max('number');
        $n = is_string($last) ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Aviso a los administradores de la organización (lo entrega el módulo de notificaciones).
     */
    private function notice(string $invoiceId, string $kind): void
    {
        $inv = DB::table('saas_invoices')->where('id', $invoiceId)->first(['id', 'tenant_id', 'number', 'einvoice_series', 'einvoice_number', 'total', 'currency', 'due_on']);
        if ($inv === null) {
            return;
        }
        $suspendAfter = (int) $this->settings->value('billing.suspend_after_overdue_days');
        $suspendOn = $suspendAfter > 0 && $inv->due_on !== null ? Carbon::parse((string) $inv->due_on)->addDays($suspendAfter + 1)->toDateString() : null;
        TenancyScope::runAs((string) $inv->tenant_id, fn () => $this->outbox->record(DomainEventTypes::SAAS_BILLING_NOTICE, 'saas_invoice', (string) $inv->id, [
            'invoice_id' => (string) $inv->id, 'kind' => $kind,
            'number' => $inv->einvoice_number !== null ? $inv->einvoice_series.'-'.$inv->einvoice_number : (string) $inv->number,
            'total' => (string) $inv->total, 'currency' => (string) $inv->currency, 'due_on' => $inv->due_on, 'suspend_on' => $suspendOn,
        ]));
    }

    /**
     * @param  array<string, mixed>|null  $subscription
     * @param  list<array<string, mixed>>  $invoices
     * @param  list<array<string, mixed>>  $payments
     * @param  callable(string): mixed  $setting
     * @return array<string, mixed>
     */
    private function statement(?array $subscription, array $invoices, array $payments, callable $setting): array
    {
        $balance = [];
        $overdue = 0;
        $nextDue = null;
        foreach ($invoices as $i) {
            if (! in_array($i['status'], ['issued', 'overdue'], true)) {
                continue;
            }
            $currency = (string) $i['total']['currency'];
            $balance[$currency] = $this->decimalAdd($balance[$currency] ?? '0', (string) $i['balance']['amount']);
            $overdue += $i['status'] === 'overdue' ? 1 : 0;
            if ($i['status'] === 'issued' && is_string($i['due_on']) && ($nextDue === null || $i['due_on'] < $nextDue)) {
                $nextDue = $i['due_on'];
            }
        }

        return [
            'subscription' => $subscription,
            'balance' => array_map(fn (string $c, string $a): array => ['amount' => $a, 'currency' => $c], array_keys($balance), $balance),
            'overdue_invoices' => $overdue,
            'next_due_on' => $nextDue,
            'dunning' => ['reminder_days_before_due' => (int) $setting('billing.reminder_days_before_due'), 'suspend_after_overdue_days' => (int) $setting('billing.suspend_after_overdue_days')],
            'invoices' => $invoices,
            'payments' => $payments,
        ];
    }

    private function decimalAdd(string $a, string $b): string
    {
        return (string) DB::selectOne('SELECT (?::numeric + ?::numeric)::text AS v', [$a, $b])?->v;
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionSummary(\stdClass $sub): array
    {
        $plan = DB::table('plans')->where('id', $sub->plan_id)->first(['name', 'price', 'currency']);
        $price = $sub->price ?? $plan?->price;
        $currency = $sub->currency ?? $plan?->currency;

        return [
            'plan' => (string) $plan?->name, 'status' => (string) $sub->status, 'billing_cycle' => (string) $sub->billing_cycle,
            'starts_on' => (string) $sub->starts_on, 'trial_ends_on' => $sub->trial_ends_on,
            ...($price === null || $currency === null ? [] : ['price' => ['amount' => (string) $price, 'currency' => (string) $currency]]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(\stdClass $r, bool $detail): array
    {
        $out = $this->presentFromArray((array) $r);
        $tenant = DB::table('tenants')->where('id', $r->tenant_id)->first(['name', 'slug']);
        $out += [
            'tenant_id' => (string) $r->tenant_id,
            'tenant' => ['name' => (string) $tenant?->name, 'slug' => (string) $tenant?->slug],
            'subscription_id' => $r->subscription_id === null ? null : (string) $r->subscription_id,
            'buyer_tax_id' => $r->buyer_tax_id, 'buyer_name' => $r->buyer_name,
            'tax_treatment' => $r->tax_treatment, 'tax_rate' => $r->tax_rate === null ? null : (string) $r->tax_rate,
            'einvoice_ticket' => $r->einvoice_ticket, 'einvoice_attempts' => (int) $r->einvoice_attempts,
            'void_reason' => $r->void_reason,
            'allowed_actions' => array_values(array_filter($this->machine->allowedActions((string) $r->status), fn (string $a): bool => in_array($a, [SaasInvoiceStateMachine::ISSUE, SaasInvoiceStateMachine::VOID], true))),
            'version' => (int) $r->lock_version,
        ];
        if ($detail) {
            $out['lines'] = array_values(DB::table('saas_invoice_lines')->where('saas_invoice_id', $r->id)->orderBy('created_at')->orderBy('id')->get()
                ->map(fn ($l): array => [
                    'description' => (string) $l->description, 'quantity' => (string) $l->quantity,
                    'unit_price' => ['amount' => (string) $l->unit_price, 'currency' => (string) $l->currency],
                    'amount' => ['amount' => (string) $l->amount, 'currency' => (string) $l->currency],
                ])->all());
            $out['payments'] = array_values(DB::table('saas_payments')->where('saas_invoice_id', $r->id)->orderBy('paid_on')->get()
                ->map(fn ($p): array => $this->presentPayment($p))->all());
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function presentFromArray(array $r): array
    {
        $currency = (string) $r['currency'];
        $money = fn (mixed $v): array => ['amount' => (string) $v, 'currency' => $currency];
        $balance = (string) DB::selectOne('SELECT (?::numeric - ?::numeric)::text AS v', [(string) $r['total'], (string) $r['amount_paid']])?->v;
        $ts = fn (mixed $v): ?string => $v === null ? null : Carbon::parse((string) $v)->toIso8601String();

        return [
            'id' => (string) $r['id'], 'number' => (string) $r['number'], 'status' => (string) $r['status'],
            'period_start' => (string) $r['period_start'], 'period_end' => (string) $r['period_end'],
            'subtotal' => $money($r['subtotal']), 'tax' => $money($r['tax']), 'total' => $money($r['total']),
            'amount_paid' => $money($r['amount_paid']), 'balance' => $money($balance),
            'issued_at' => $ts($r['issued_at']), 'due_on' => $r['due_on'] === null ? null : (string) $r['due_on'], 'paid_at' => $ts($r['paid_at']),
            'einvoice' => [
                'status' => (string) $r['einvoice_status'], 'series' => $r['einvoice_series'], 'number' => $r['einvoice_number'],
                'acceptance_hash' => $r['einvoice_hash'], 'accepted_at' => $ts($r['einvoice_accepted_at']), 'message' => $r['einvoice_message'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPayment(\stdClass $p): array
    {
        return [
            'id' => (string) $p->id, 'invoice_id' => (string) $p->saas_invoice_id,
            'amount' => ['amount' => (string) $p->amount, 'currency' => (string) $p->currency],
            'paid_on' => (string) $p->paid_on, 'method' => (string) $p->method, 'status' => (string) $p->status,
            'source' => (string) $p->source, 'reference' => $p->reference,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $id): array
    {
        $r = DB::table('saas_invoices')->where('id', $id)->first(['number', 'status', 'subtotal', 'tax', 'total', 'amount_paid', 'currency', 'einvoice_status', 'einvoice_series', 'einvoice_number', 'due_on']);

        return $r === null ? [] : (array) $r;
    }
}
