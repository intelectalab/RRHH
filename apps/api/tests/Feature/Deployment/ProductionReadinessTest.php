<?php

/**
 * QA-17 · Piezas de la salida a producción que viven en la API: la consulta interna del proxy HTTPS antes de
 * emitir un certificado y el correo real por SMTP.
 */

use App\Shared\Integrations\Email\SmtpEmailAdapter;
use App\Shared\Integrations\IntegrationManager;
use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

const TLS_TENANT = 'dddddddd-1111-4000-8000-000000000004';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    config()->set('tenancy.base_domain', 'staging.gestiahr.test');
    DB::table('tenants')->insertOrIgnore([
        ['id' => TLS_TENANT, 'slug' => 'tls-org', 'name' => 'Org TLS', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
    DB::table('tenant_domains')->insertOrIgnore([
        ['tenant_id' => TLS_TENANT, 'domain' => 'rrhh.verificado.pe', 'kind' => 'custom', 'verified_at' => now()],
        ['tenant_id' => TLS_TENANT, 'domain' => 'rrhh.pendiente.pe', 'kind' => 'custom', 'verified_at' => null],
    ]);
});

test('QA-17 · el proxy HTTPS solo obtiene certificado para hosts de la plataforma', function (string $domain, int $status): void {
    $this->get('/api/internal/tls/allowed?domain='.urlencode($domain))->assertStatus($status);
})->with([
    'dominio base' => ['staging.gestiahr.test', 200],
    'consola' => ['admin.staging.gestiahr.test', 200],
    'api' => ['api.staging.gestiahr.test', 200],
    'organización existente' => ['tls-org.staging.gestiahr.test', 200],
    'mayúsculas' => ['TLS-ORG.staging.gestiahr.test', 200],
    'dominio propio verificado' => ['rrhh.verificado.pe', 200],
    'dominio propio sin verificar' => ['rrhh.pendiente.pe', 404],
    'organización inexistente' => ['nadie.staging.gestiahr.test', 404],
    'dos niveles' => ['a.tls-org.staging.gestiahr.test', 404],
    'host ajeno' => ['ejemplo.com', 404],
    'vacío' => ['', 404],
    'caracteres inválidos' => ['tls-org.staging.gestiahr.test/../x', 404],
]);

test('QA-17 · con EMAIL_PROVIDER=smtp el correo sale por el SMTP configurado y no por el falso', function (): void {
    config()->set('integrations.email_provider', 'smtp');
    Mail::fake();
    $manager = new IntegrationManager(app(IntegrationCallRecorder::class));

    $email = $manager->email();
    expect($email)->toBeInstanceOf(SmtpEmailAdapter::class)
        ->and($email->isFake())->toBeFalse()
        ->and($email->verifySender('rrhh@cliente.pe'))->toBe('pending')
        ->and($email->parseFeedback('{}', []))->toBe([]);

    $ref = $email->send('ana@cliente.pe', 'Asunto', 'Cuerpo', ['boleta.pdf' => '%PDF-1.4']);
    expect($ref)->toStartWith('smtp_');
});

test('QA-17 · sin EMAIL_PROVIDER, fuera de producción el correo sigue siendo el falso', function (): void {
    config()->set('integrations.email_provider', null);
    $manager = new IntegrationManager(app(IntegrationCallRecorder::class));
    expect($manager->email()->isFake())->toBeTrue();
});
