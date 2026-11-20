<?php

/**
 * SAS-11 · ADR 0010 · Cobro de la suscripción: el cargo del periodo nace con los precios guardados, el comprobante
 * electrónico sale al proveedor (adaptador falso) y solo queda emitido con su constancia de aceptación; un rechazo lo
 * deja observado. Los pagos (manuales o de la pasarela) lo concilian, la mora suspende según la regla de la consola y
 * pagar lo vencido reactiva. La organización ve su estado de cuenta, nunca el de otra.
 */

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Organization\Infrastructure\Models\LegalEntity;
use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use App\Shared\Integrations\Fake\FakeEInvoicingAdapter;
use App\Shared\Integrations\Fake\FakePaymentGatewayAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\Models\OutboxEvent;
use App\Shared\Outbox\OutboxService;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Permissions\PermissionResolver;
use App\Shared\Security\Totp;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(DatabaseTransactions::class);

const SB_A = 'cccccccc-5a11-4000-8000-00000000000a';
const SB_B = 'cccccccc-5a11-4000-8000-00000000000b';
const SB_PASSWORD = 'Cobro-Seguro-2026!';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore([
        ['id' => SB_A, 'slug' => 'sb-andes', 'name' => 'Andes Cobro', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => SB_B, 'slug' => 'sb-costa', 'name' => 'Costa Cobro', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::select('SELECT seed_tenant_defaults(?)', [SB_A]);
    DB::select('SELECT seed_tenant_defaults(?)', [SB_B]);
    TenancyScope::runAs(SB_A, fn () => LegalEntity::factory()->create(['tax_id' => '20999999911', 'legal_name' => 'Andes Cobro S.A.C.']));
    // RUC mal registrado: el proveedor rechaza el comprobante.
    TenancyScope::runAs(SB_B, fn () => LegalEntity::factory()->create(['tax_id' => '2099', 'legal_name' => 'Costa Cobro S.A.C.']));

    $this->plan = (string) Str::uuid();
    DB::table('plans')->insert(['id' => $this->plan, 'code' => 'sb-plan-'.Str::random(4), 'name' => 'Plan Cobro', 'status' => 'active', 'billing_cycle' => 'monthly', 'price' => '500.00', 'currency' => 'PEN']);
    $this->subA = (string) Str::uuid();
    $this->subB = (string) Str::uuid();
    DB::table('subscriptions')->insert([
        ['id' => $this->subA, 'tenant_id' => SB_A, 'plan_id' => $this->plan, 'status' => 'active', 'billing_cycle' => 'monthly', 'starts_on' => '2026-10-01'],
        ['id' => $this->subB, 'tenant_id' => SB_B, 'plan_id' => $this->plan, 'status' => 'active', 'billing_cycle' => 'monthly', 'starts_on' => '2026-10-01'],
    ]);

    $this->einvoicing = new FakeEInvoicingAdapter;
    $this->gateway = new FakePaymentGatewayAdapter;
    app(IntegrationManager::class)->setEInvoicingAdapter($this->einvoicing);
    app(IntegrationManager::class)->setPaymentsAdapter($this->gateway);

    $this->adminA = sbUser(SB_A);
    $this->adminB = sbUser(SB_B);
    PlatformUser::create(['email' => 'cobros@gestia.test', 'full_name' => 'Cobros', 'role' => 'superadmin', 'status' => 'active', 'password_hash' => Hash::make(SB_PASSWORD)]);
    $this->token = sbEnrolled('cobros@gestia.test');
});

afterEach(fn () => TenancyScope::clear());

function sbUser(string $tenant): User
{
    return TenancyScope::runAs($tenant, function (): User {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'admin_empresa')->firstOrFail()->id]);

        return $user;
    });
}

/**
 * @param  array<string, mixed>  $body
 */
function sbConsole(?string $token, string $method, string $path, array $body = []): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    $request = test()->flushHeaders();
    $headers = ['Idempotency-Key' => (string) Str::uuid()];
    if ($token !== null) {
        $request = $request->withCredentials()->withUnencryptedCookie('gestia_console_session', $token);
        $headers['X-XSRF-TOKEN'] = hash_hmac('sha256', 'console-xsrf|'.$token, (string) config('app.key'));
    } else {
        $request = $request->withUnencryptedCookie('gestia_console_session', '');
    }

    return $request->withHeaders($headers)->json($method, '/api/v1/platform'.$path, $body);
}

function sbEnrolled(string $email): string
{
    $login = sbConsole(null, 'POST', '/auth/login', ['email' => $email, 'password' => SB_PASSWORD])->assertOk();
    $token = (string) $login->getCookie('gestia_console_session', false)?->getValue();
    $secret = (string) sbConsole($token, 'POST', '/auth/mfa/totp')->assertOk()->json('data.secret');
    sbConsole($token, 'POST', '/auth/mfa/totp/confirm', ['code' => Totp::at(Totp::base32Decode($secret), Totp::currentStep())])->assertOk();

    return $token;
}

function sbApp(User $user, string $path, string $tenant): TestResponse
{
    TenancyScope::clear();
    app('auth')->forgetGuards();
    app(ModuleGate::class)->forget();
    app(PermissionResolver::class)->forget();
    $response = test()->flushHeaders()->actingAs($user, 'ayni')->withHeaders(['X-Tenant-Id' => $tenant])->json('GET', '/api/v1'.$path);
    TenancyScope::clear();

    return $response;
}

/** Procesa el outbox de una organización como outbox:dispatch (consumo idempotente por evento). */
function sbDispatch(string $tenant, ?string $type = null): void
{
    $outbox = app(OutboxService::class);
    $events = TenancyScope::runAs($tenant, fn () => OutboxEvent::query()->when($type, fn ($q) => $q->where('event_type', $type))->orderBy('occurred_at')->get());
    foreach ($events as $event) {
        foreach ($outbox->getSubscribersFor($event->event_type) as $subscriber) {
            TenancyScope::runAs($tenant, fn () => $outbox->getTracker()->executeIdempotently($event->tenant_id, $subscriber->consumerName(), $event->id, fn () => $subscriber->handle($event)));
        }
    }
    TenancyScope::clear();
}

/**
 * @return list<string>
 */
function sbNotices(string $tenant): array
{
    return TenancyScope::runAs($tenant, fn () => DB::table('outbox_events')->where('event_type', DomainEventTypes::SAAS_BILLING_NOTICE)->orderBy('occurred_at')
        ->pluck('payload')->map(fn ($p): string => (string) json_decode((string) $p, true)['kind'])->all());
}

function sbIgv(): void
{
    // Dato de prueba: en producción el valor lo valida el contador y llega por el catálogo de parámetros legales.
    DB::table('legal_parameters')->where('country_code', 'PE')->where('code', 'igv_tasa_pct')->delete();
    DB::table('legal_parameters')->insert(['country_code' => 'PE', 'code' => 'igv_tasa_pct', 'name' => 'Tasa del impuesto (prueba)', 'value_numeric' => '18', 'unit' => 'percent', 'valid_from' => '2020-01-01', 'verified' => true]);
}

test('SAS-11 · el cargo nace con los precios guardados; sin tasa validada no se emite; con constancia queda emitido y la organización lo ve', function (): void {
    $gen = sbConsole($this->token, 'POST', "/subscriptions/{$this->subA}/invoices");
    $this->assertMatchesOpenApi($gen, 'post', '/platform/subscriptions/{id}/invoices', 201);
    $id = (string) $gen->json('data.id');
    expect($gen->json('data.status'))->toBe('draft')
        ->and($gen->json('data.period_start'))->toBe('2026-10-01')
        ->and($gen->json('data.period_end'))->toBe('2026-10-31')
        ->and($gen->json('data.subtotal'))->toBe(['amount' => '500.00', 'currency' => 'PEN'])
        ->and($gen->json('data.lines.0.amount'))->toBe(['amount' => '500.00', 'currency' => 'PEN']);

    // El mismo periodo no se cobra dos veces; el siguiente sí.
    $dup = sbConsole($this->token, 'POST', "/subscriptions/{$this->subA}/invoices", ['period_start' => '2026-10-01']);
    $this->assertMatchesOpenApi($dup, 'post', '/platform/subscriptions/{id}/invoices', 409);
    expect(sbConsole($this->token, 'POST', "/subscriptions/{$this->subA}/invoices")->assertCreated()->json('data.period_start'))->toBe('2026-11-01');

    // La tasa del impuesto está por validar: no se emite.
    $pending = sbConsole($this->token, 'POST', "/invoices/{$id}/issue");
    $this->assertMatchesOpenApi($pending, 'post', '/platform/invoices/{id}/issue', 422);
    expect(DB::table('saas_invoices')->where('id', $id)->value('einvoice_status'))->toBe('not_sent');

    sbIgv();
    $issue = sbConsole($this->token, 'POST', "/invoices/{$id}/issue");
    $this->assertMatchesOpenApi($issue, 'post', '/platform/invoices/{id}/issue', 200);
    sbDispatch(SB_A, DomainEventTypes::SAAS_INVOICE_ISSUE_REQUESTED);
    sbDispatch(SB_A, DomainEventTypes::SAAS_INVOICE_ISSUE_REQUESTED); // una entrega doble no emite otro comprobante

    $show = sbConsole($this->token, 'GET', "/invoices/{$id}");
    $this->assertMatchesOpenApi($show, 'get', '/platform/invoices/{id}', 200);
    expect($show->json('data.status'))->toBe('issued')
        ->and($show->json('data.tax'))->toBe(['amount' => '90.00', 'currency' => 'PEN'])
        ->and($show->json('data.total'))->toBe(['amount' => '590.00', 'currency' => 'PEN'])
        ->and($show->json('data.einvoice.status'))->toBe('accepted')
        ->and($show->json('data.einvoice.number'))->toBe('00000001')
        ->and($show->json('data.einvoice.acceptance_hash'))->not->toBeNull()
        ->and($show->json('data.buyer_tax_id'))->toBe('20999999911')
        ->and($show->json('data.due_on'))->not->toBeNull()
        ->and(sbNotices(SB_A))->toBe(['issued'])
        ->and(DB::table('platform_audit_events')->where('entity_id', $id)->where('action', 'billing.einvoice_accepted')->count())->toBe(1);

    // La organización ve su plan y su comprobante con la constancia; no los borradores ni lo de otra organización.
    $mine = sbApp($this->adminA, '/billing/account', SB_A);
    $this->assertMatchesOpenApi($mine, 'get', '/billing/account', 200);
    expect($mine->json('data.subscription.plan'))->toBe('Plan Cobro')
        ->and(collect($mine->json('data.invoices'))->pluck('id')->all())->toBe([$id])
        ->and($mine->json('data.invoices.0.einvoice.status'))->toBe('accepted')
        ->and($mine->json('data.balance'))->toBe([['amount' => '590.00', 'currency' => 'PEN']]);
    expect(sbApp($this->adminB, '/billing/account', SB_B)->assertOk()->json('data.invoices'))->toBe([]);
});

test('SAS-11 · un comprobante rechazado queda observado y no se da por emitido; se anula con motivo y uno aceptado no', function (): void {
    sbIgv();
    $id = (string) sbConsole($this->token, 'POST', "/subscriptions/{$this->subB}/invoices")->assertCreated()->json('data.id');
    sbConsole($this->token, 'POST', "/invoices/{$id}/issue")->assertOk();
    sbDispatch(SB_B, DomainEventTypes::SAAS_INVOICE_ISSUE_REQUESTED);

    $show = sbConsole($this->token, 'GET', "/invoices/{$id}")->assertOk();
    expect($show->json('data.status'))->toBe('observed')
        ->and($show->json('data.einvoice.status'))->toBe('rejected')
        ->and($show->json('data.einvoice.message'))->toContain('RUC')
        ->and($show->json('data.issued_at'))->toBeNull()
        ->and(sbNotices(SB_B))->toBe([]);
    // Un observado no se cobra.
    $pay = sbConsole($this->token, 'POST', "/invoices/{$id}/payments", ['amount' => ['amount' => '10.00', 'currency' => 'PEN'], 'paid_on' => now()->toDateString()]);
    $this->assertMatchesOpenApi($pay, 'post', '/platform/invoices/{id}/payments', 409);
    // La organización ve el observado con su motivo, sin saldo.
    expect(sbApp($this->adminB, '/billing/account', SB_B)->assertOk()->json('data.balance'))->toBe([]);

    $noReason = sbConsole($this->token, 'POST', "/invoices/{$id}/void", []);
    $this->assertMatchesOpenApi($noReason, 'post', '/platform/invoices/{id}/void', 422);
    sbConsole($this->token, 'POST', "/invoices/{$id}/void", ['reason' => 'RUC por corregir'])->assertOk()->assertJsonPath('data.status', 'void');

    // Uno aceptado no se anula: exige nota de crédito.
    $ok = (string) sbConsole($this->token, 'POST', "/subscriptions/{$this->subA}/invoices")->assertCreated()->json('data.id');
    sbConsole($this->token, 'POST', "/invoices/{$ok}/issue")->assertOk();
    sbDispatch(SB_A, DomainEventTypes::SAAS_INVOICE_ISSUE_REQUESTED);
    $void = sbConsole($this->token, 'POST', "/invoices/{$ok}/void", ['reason' => 'error']);
    $this->assertMatchesOpenApi($void, 'post', '/platform/invoices/{id}/void', 409);
});

test('SAS-11 · los pagos concilian el comprobante; la pasarela verifica y no se registra dos veces', function (): void {
    sbIgv();
    $id = (string) sbConsole($this->token, 'POST', "/subscriptions/{$this->subA}/invoices")->assertCreated()->json('data.id');
    sbConsole($this->token, 'POST', "/invoices/{$id}/issue")->assertOk();
    sbDispatch(SB_A, DomainEventTypes::SAAS_INVOICE_ISSUE_REQUESTED);
    $today = now()->toDateString();

    $other = sbConsole($this->token, 'POST', "/invoices/{$id}/payments", ['amount' => ['amount' => '10.00', 'currency' => 'USD'], 'paid_on' => $today]);
    $this->assertMatchesOpenApi($other, 'post', '/platform/invoices/{id}/payments', 422);
    $tooMuch = sbConsole($this->token, 'POST', "/invoices/{$id}/payments", ['amount' => ['amount' => '600.00', 'currency' => 'PEN'], 'paid_on' => $today]);
    $this->assertMatchesOpenApi($tooMuch, 'post', '/platform/invoices/{id}/payments', 422);

    $part = sbConsole($this->token, 'POST', "/invoices/{$id}/payments", ['amount' => ['amount' => '290.00', 'currency' => 'PEN'], 'paid_on' => $today, 'method' => 'transfer', 'reference' => 'OP-1']);
    $this->assertMatchesOpenApi($part, 'post', '/platform/invoices/{id}/payments', 201);
    expect($part->json('data.status'))->toBe('issued')->and($part->json('data.balance'))->toBe(['amount' => '300.00', 'currency' => 'PEN']);

    $unknown = sbConsole($this->token, 'POST', "/invoices/{$id}/payments", ['gateway_ref' => 'PG-NO-EXISTE']);
    $this->assertMatchesOpenApi($unknown, 'post', '/platform/invoices/{id}/payments', 422);
    $this->gateway->willReport('PG-1', '300.00', 'PEN', $today);
    $paid = sbConsole($this->token, 'POST', "/invoices/{$id}/payments", ['gateway_ref' => 'PG-1'])->assertCreated();
    expect($paid->json('data.status'))->toBe('paid')
        ->and($paid->json('data.balance'))->toBe(['amount' => '0.00', 'currency' => 'PEN'])
        ->and(collect($paid->json('data.payments'))->pluck('source')->all())->toBe(['manual', 'gateway']);
    $again = sbConsole($this->token, 'POST', "/invoices/{$id}/payments", ['gateway_ref' => 'PG-1']);
    $this->assertMatchesOpenApi($again, 'post', '/platform/invoices/{id}/payments', 409);
    expect(DB::table('saas_payments')->where('saas_invoice_id', $id)->count())->toBe(2);
});

test('SAS-11 · la mora avisa antes, marca el vencido, suspende según la regla de la consola y pagar lo vencido reactiva', function (): void {
    sbIgv();
    sbConsole($this->token, 'PATCH', '/settings/billing.suspend_after_overdue_days', ['value' => 3])->assertOk();
    $id = (string) sbConsole($this->token, 'POST', "/subscriptions/{$this->subA}/invoices")->assertCreated()->json('data.id');
    sbConsole($this->token, 'POST', "/invoices/{$id}/issue")->assertOk();
    sbDispatch(SB_A, DomainEventTypes::SAAS_INVOICE_ISSUE_REQUESTED);

    // Aviso previo: vence en dos días (el aviso es a tres) y se envía una sola vez.
    DB::table('saas_invoices')->where('id', $id)->update(['due_on' => now()->addDays(2)->toDateString()]);
    $this->artisan('billing:dunning')->assertSuccessful();
    $this->artisan('billing:dunning')->assertSuccessful();
    expect(sbNotices(SB_A))->toBe(['issued', 'due_soon']);

    // Vencido: la suscripción queda en mora y se avisa con la fecha de suspensión.
    DB::table('saas_invoices')->where('id', $id)->update(['due_on' => now()->subDay()->toDateString()]);
    $this->artisan('billing:dunning')->assertSuccessful();
    expect(DB::table('saas_invoices')->where('id', $id)->value('status'))->toBe('overdue')
        ->and(DB::table('subscriptions')->where('id', $this->subA)->value('status'))->toBe('past_due')
        ->and(DB::table('tenants')->where('id', SB_A)->value('status'))->toBe('active')
        ->and(sbNotices(SB_A))->toBe(['issued', 'due_soon', 'overdue']);
    $statement = sbConsole($this->token, 'GET', '/tenants/'.SB_A.'/account-statement');
    $this->assertMatchesOpenApi($statement, 'get', '/platform/tenants/{id}/account-statement', 200);
    expect($statement->json('data.overdue_invoices'))->toBe(1)->and($statement->json('data.dunning.suspend_after_overdue_days'))->toBe(3);

    // Más allá de los días de mora: suspendida (la organización no opera; sus datos se conservan).
    DB::table('saas_invoices')->where('id', $id)->update(['due_on' => now()->subDays(5)->toDateString()]);
    $this->artisan('billing:dunning')->assertSuccessful();
    expect(DB::table('subscriptions')->where('id', $this->subA)->value('status'))->toBe('suspended')
        ->and(DB::table('tenants')->where('id', SB_A)->value('status'))->toBe('suspended');

    // Pagar lo vencido reactiva sin pérdida.
    sbConsole($this->token, 'POST', "/invoices/{$id}/payments", ['amount' => ['amount' => '590.00', 'currency' => 'PEN'], 'paid_on' => now()->toDateString()])->assertCreated()
        ->assertJsonPath('data.status', 'paid');
    expect(DB::table('subscriptions')->where('id', $this->subA)->value('status'))->toBe('active')
        ->and(DB::table('tenants')->where('id', SB_A)->value('status'))->toBe('active');

    // El aviso llega a los administradores de la organización.
    sbDispatch(SB_A, DomainEventTypes::SAAS_BILLING_NOTICE);
    expect(TenancyScope::runAs(SB_A, fn () => DB::table('notifications')->where('recipient_user_id', $this->adminA->id)->where('category', 'billing.overdue')->count()))->toBe(1);
});

test('SAS-11 · permisos de la consola y de la organización', function (): void {
    PlatformUser::create(['email' => 'lector@gestia.test', 'full_name' => 'Lector', 'role' => 'readonly', 'status' => 'active', 'password_hash' => Hash::make(SB_PASSWORD)]);
    $reader = sbEnrolled('lector@gestia.test');
    $list = sbConsole($reader, 'GET', '/invoices?tenant_id='.SB_A);
    $this->assertMatchesOpenApi($list, 'get', '/platform/invoices', 200);
    $forbidden = sbConsole($reader, 'POST', "/subscriptions/{$this->subA}/invoices");
    $this->assertMatchesOpenApi($forbidden, 'post', '/platform/subscriptions/{id}/invoices', 403);
    $this->assertMatchesOpenApi(sbConsole(null, 'GET', '/invoices'), 'get', '/platform/invoices', 401);
    $bad = sbConsole($this->token, 'GET', '/invoices?status=perdido');
    $this->assertMatchesOpenApi($bad, 'get', '/platform/invoices', 422);
    $this->assertMatchesOpenApi(sbConsole($this->token, 'GET', '/invoices/'.Str::uuid()), 'get', '/platform/invoices/{id}', 404);

    // Un colaborador sin permiso no ve el estado de cuenta.
    $employee = TenancyScope::runAs(SB_A, function (): User {
        $user = User::factory()->create();
        $user->roleAssignments()->create(['role_id' => Role::query()->where('system_role_code', 'empleado')->firstOrFail()->id]);

        return $user;
    });
    $denied = sbApp($employee, '/billing/account', SB_A);
    $this->assertMatchesOpenApi($denied, 'get', '/billing/account', 403);
    // El rol de aplicación no lee las tablas del cobro.
    expect(fn () => DB::transaction(fn () => TenancyScope::runAs(SB_A, fn () => DB::table('saas_invoices')->count())))->toThrow(Exception::class);
});
