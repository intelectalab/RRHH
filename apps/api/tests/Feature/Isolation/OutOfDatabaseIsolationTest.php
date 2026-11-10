<?php

/**
 * TEN-6 · Aislamiento fuera de la base de datos: colas, caché y archivos.
 * La búsqueda global, las descargas y las exportaciones con endpoint quedan
 * cubiertas por la batería de CrossTenantIsolationTest al registrarse.
 */

use App\Shared\Cache\TenantCache;
use App\Shared\Files\FileService;
use App\Shared\Tenancy\Queue\TenantQueueContext;
use App\Shared\Tenancy\RunsWithoutTenant;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Tenancy\TenantContextRequiredException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const OOD_TENANT_A = 'aaaaaaaa-6666-4000-8000-00000000000a';
const OOD_TENANT_B = 'bbbbbbbb-6666-4000-8000-00000000000b';

final class OodProbeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @var array<int, string|null> */
    public static array $seen = [];

    public function handle(): void
    {
        self::$seen[] = TenancyScope::current();
    }
}

final class OodPlatformJob implements RunsWithoutTenant, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static bool $ran = false;

    public function handle(): void
    {
        self::$ran = true;
    }
}

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }

    TenancyScope::clear();
    OodProbeJob::$seen = [];
    OodPlatformJob::$ran = false;
    DB::table('tenants')->insertOrIgnore([
        ['id' => OOD_TENANT_A, 'slug' => 'ood-org-a', 'name' => 'Ood A', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
        ['id' => OOD_TENANT_B, 'slug' => 'ood-org-b', 'name' => 'Ood B', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN'],
    ]);
});

afterEach(function (): void {
    TenancyScope::clear();
});

test('un trabajo sin organización declarada no se encola', function (): void {
    expect(fn () => OodProbeJob::dispatch())->toThrow(TenantContextRequiredException::class);
    expect(OodProbeJob::$seen)->toBe([]);
});

test('un trabajo de plataforma marcado RunsWithoutTenant sí se encola sin organización', function (): void {
    OodPlatformJob::dispatch();

    expect(OodPlatformJob::$ran)->toBeTrue();
});

test('el trabajo se ejecuta con la organización que lo originó y se restaura el contexto', function (): void {
    // dispatch() encola al destruirse el PendingDispatch: debe ocurrir dentro del contexto.
    TenancyScope::runAs(OOD_TENANT_A, function (): void {
        OodProbeJob::dispatch();
    });
    TenancyScope::runAs(OOD_TENANT_B, function (): void {
        OodProbeJob::dispatch();
    });

    expect(OodProbeJob::$seen)->toBe([OOD_TENANT_A, OOD_TENANT_B])
        ->and(TenancyScope::current())->toBeNull();
});

test('el payload del trabajo lleva tenant_id y la etiqueta tenant:{id}', function (): void {
    Queue::fake();
    $payload = TenancyScope::runAs(OOD_TENANT_A, fn () => TenantQueueContext::payloadFor([
        'data' => ['commandName' => OodProbeJob::class],
    ]));

    expect($payload['tenant_id'])->toBe(OOD_TENANT_A)
        ->and($payload['tags'])->toContain('tenant:'.OOD_TENANT_A);
});

test('la caché separa las claves por organización', function (): void {
    $cache = app(TenantCache::class);

    TenancyScope::runAs(OOD_TENANT_A, fn () => $cache->put('dashboard', 'datos-de-A', 60));

    expect(TenancyScope::runAs(OOD_TENANT_B, fn () => $cache->get('dashboard')))->toBeNull()
        ->and(TenancyScope::runAs(OOD_TENANT_A, fn () => $cache->get('dashboard')))->toBe('datos-de-A')
        ->and(fn () => $cache->get('dashboard'))->toThrow(RuntimeException::class);
});

test('una descarga firmada de un archivo de otra organización responde no encontrado', function (): void {
    Storage::fake('s3');
    config(['files.disk' => 's3', 'files.allowed_mimes' => ['application/pdf']]);
    $files = app(FileService::class);

    $file = TenancyScope::runAs(OOD_TENANT_A, fn () => DB::transaction(fn () => $files->upload('%PDF-1.4', 'boleta.pdf', 'application/pdf')));

    expect(TenancyScope::runAs(OOD_TENANT_A, fn () => $files->getSignedUrl($file->id)))->toBeString();
    expect(fn () => TenancyScope::runAs(OOD_TENANT_B, fn () => $files->getSignedUrl($file->id)))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => TenancyScope::runAs(OOD_TENANT_B, fn () => $files->getSignedUrl($file->id, OOD_TENANT_B)))
        ->toThrow(ModelNotFoundException::class);
});

test('sin organización activa no se firma ni se borra ningún archivo', function (): void {
    $files = app(FileService::class);

    expect(fn () => $files->getSignedUrl('00000000-0000-4000-8000-000000000000'))->toThrow(TenantContextRequiredException::class)
        ->and(fn () => $files->delete('00000000-0000-4000-8000-000000000000'))->toThrow(TenantContextRequiredException::class);
});

test('los archivos se guardan bajo tenants/{tenant_id}/', function (): void {
    Storage::fake('s3');
    config(['files.disk' => 's3', 'files.allowed_mimes' => ['application/pdf']]);

    $file = TenancyScope::runAs(OOD_TENANT_B, fn () => DB::transaction(fn () => app(FileService::class)->upload('%PDF-1.4', 'x.pdf', 'application/pdf')));

    expect($file->storage_key)->toStartWith('tenants/'.OOD_TENANT_B.'/');
    Storage::disk('s3')->assertExists($file->storage_key);
});
