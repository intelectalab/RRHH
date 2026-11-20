<?php

/**
 * NOM-1 · NOM-5 · NOM-9 · Casos dorados de la mecánica del núcleo (tests/Golden/Engine/<caso>/). Comparación exacta al
 * céntimo de cada línea, los totales y los tramos. Los casos de norma peruana van aparte en
 * packages/country-pe/tests/golden y los escribe el especialista (kit/docs/11 §8): el desarrollador nunca
 * cambia un resultado esperado para hacer pasar la prueba.
 */

use App\Modules\Payroll\Application\PayrollEngine;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\IdentityFixtures;
use Tests\Support\PayrollFixtures;

uses(DatabaseTransactions::class)->group('golden');

const GLD_TENANT = 'aaaaaaaa-901d-4000-8000-00000000000a';

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    DB::table('tenants')->insertOrIgnore(['id' => GLD_TENANT, 'slug' => 'gld-org', 'name' => 'Golden', 'status' => 'active', 'country_code' => 'PE', 'default_currency' => 'PEN']);
    DB::select('SELECT seed_tenant_defaults(?)', [GLD_TENANT]);
    TenancyScope::runAs(GLD_TENANT, fn () => IdentityFixtures::enableAllModules());
});

afterEach(fn () => TenancyScope::clear());

$cases = array_map('basename', glob(__DIR__.'/Engine/*', GLOB_ONLYDIR) ?: []);

test('caso dorado del motor', function (string $case): void {
    $dir = __DIR__.'/Engine/'.$case;
    $meta = Yaml::parseFile($dir.'/case.yaml');
    $input = Yaml::parseFile($dir.'/input.yaml');
    $params = is_file($dir.'/params.yaml') ? (array) Yaml::parseFile($dir.'/params.yaml') : [];
    $expected = Yaml::parseFile($dir.'/expected.yaml');
    if (($meta['status'] ?? '') !== 'validated') {
        test()->markTestSkipped("{$case}: {$meta['status']} (no bloquea)");
    }

    $calc = TenancyScope::runAs(GLD_TENANT, function () use ($input, $params): array {
        $entity = PayrollFixtures::entity($input['employment']['regime']);
        $employment = PayrollFixtures::employment($entity, $input['employment']['hire_date'], $input['employment']['compensations']);
        // NOM-9 · Régimen propio del trabajador por tramos de vigencia (cambio de régimen dentro del periodo).
        foreach ((array) ($input['employment']['regimes'] ?? []) as $r) {
            DB::table('employment_labor_regimes')->insert(['tenant_id' => GLD_TENANT, 'employment_id' => $employment, 'labor_regime_code' => $r['code'],
                'valid_from' => $r['valid_from'], 'valid_to' => $r['valid_to'] ?? null]);
        }
        // BSO-2 · Entidad depositaria de la CTS (sin ella la relación queda con error, RN-BSO-05).
        if (! empty($input['employment']['cts_account'])) {
            PayrollFixtures::ctsAccount($employment);
        }
        foreach ($input['concepts'] as $c) {
            PayrollFixtures::concept($c);
        }
        foreach ($params as $p) {
            PayrollFixtures::parameter($p['code'], (string) $p['value'], $p['valid_from']);
        }

        return app(PayrollEngine::class)->calculate($employment, $input['period']['from'], $input['period']['to'], $input['period']['kind'],
            null, (array) ($input['variables'] ?? []), (array) ($input['incidents'] ?? []));
    });

    expect($calc['result']['status'])->toBe($expected['status'], json_encode($calc['result']['errors']) ?: '');
    $lines = [];
    foreach ($calc['result']['lines'] as $l) {
        $lines[$l['concept_code']] = $l['amount']['amount'];
    }
    expect($lines)->toBe($expected['lines'])
        ->and($calc['result']['totals'])->toBe($expected['totals'])
        ->and(array_map(fn ($s) => ['from' => $s['from'], 'to' => $s['to'], 'days' => $s['days']], $calc['context']['segments']))->toBe($expected['segments']);
})->with($cases);
