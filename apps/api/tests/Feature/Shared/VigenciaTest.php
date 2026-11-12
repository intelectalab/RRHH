<?php

/**
 * HIS-1 · Patrón de vigencia reutilizable (regla 2 de CLAUDE.md): cambiar es cerrar la fila
 * vigente e insertar otra; la base impide solapes; as_of devuelve una fila o ninguna.
 */

use App\Shared\Exceptions\AyniException;
use App\Shared\Tenancy\TenancyScope;
use App\Shared\Vigencia\EffectiveDated;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requiere PostgreSQL.');
    }
    TenancyScope::clear();
    // Dimensión de ejemplo con la misma forma que generan los «vig» del esquema del kit.
    DB::statement('CREATE TEMP TABLE vig_demo (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        tenant_id uuid NOT NULL,
        employment_id uuid NOT NULL,
        amount numeric(14,2) NOT NULL,
        valid_from date NOT NULL,
        valid_to date,
        CHECK (valid_to IS NULL OR valid_to >= valid_from),
        EXCLUDE USING gist (tenant_id WITH =, employment_id WITH =, daterange(valid_from, valid_to, \'[]\') WITH &&)
    ) ON COMMIT DROP');
    $this->vig = app(EffectiveDated::class);
    $this->rel = ['tenant_id' => (string) Str::uuid(), 'employment_id' => (string) Str::uuid()];
});

function vigAmount(?stdClass $row): ?string
{
    return $row === null ? null : (string) $row->amount;
}

test('un cambio con fecha pasada, presente y futura deja la historia correcta en cada fecha', function (): void {
    $today = now()->startOfDay();
    $past = $today->copy()->subMonths(6);
    $future = $today->copy()->addMonths(2);

    $this->vig->change('vig_demo', $this->rel, ['amount' => '1000.00'], $past);
    $this->vig->change('vig_demo', $this->rel, ['amount' => '1200.00'], $today);
    $this->vig->change('vig_demo', $this->rel, ['amount' => '1500.00'], $future);

    expect(vigAmount($this->vig->rowAt('vig_demo', $this->rel, $past->copy()->subDay())))->toBeNull()
        ->and(vigAmount($this->vig->rowAt('vig_demo', $this->rel, $past)))->toBe('1000.00')
        ->and(vigAmount($this->vig->rowAt('vig_demo', $this->rel, $today->copy()->subDay())))->toBe('1000.00')
        ->and(vigAmount($this->vig->rowAt('vig_demo', $this->rel, $today)))->toBe('1200.00')
        ->and(vigAmount($this->vig->rowAt('vig_demo', $this->rel, $future->copy()->subDay())))->toBe('1200.00')
        ->and(vigAmount($this->vig->rowAt('vig_demo', $this->rel, $future)))->toBe('1500.00')
        ->and(vigAmount($this->vig->rowAt('vig_demo', $this->rel, $future->copy()->addYears(5))))->toBe('1500.00');

    // Nada se sobrescribió: tres filas, cada una cerrada el día anterior a la siguiente.
    $history = $this->vig->history('vig_demo', $this->rel);
    expect($history)->toHaveCount(3)
        ->and(array_map(fn ($r) => [(string) $r->amount, $r->valid_from, $r->valid_to], $history))->toBe([
            ['1500.00', $future->toDateString(), null],
            ['1200.00', $today->toDateString(), $future->copy()->subDay()->toDateString()],
            ['1000.00', $past->toDateString(), $today->copy()->subDay()->toDateString()],
        ]);
});

test('la base impide solapes aunque se escriba por fuera del patrón y el error se traduce a 409 overlap', function (): void {
    $this->vig->change('vig_demo', $this->rel, ['amount' => '1000.00'], '2026-01-01');

    // Escritura directa que se solapa con la fila abierta: la restricción de exclusión la rechaza.
    expect(fn () => DB::transaction(fn () => DB::table('vig_demo')->insert($this->rel + ['amount' => '1', 'valid_from' => '2026-03-01'])))
        ->toThrow(QueryException::class);

    // Otra relación no choca.
    $other = ['tenant_id' => $this->rel['tenant_id'], 'employment_id' => (string) Str::uuid()];
    $this->vig->change('vig_demo', $other, ['amount' => '900.00'], '2026-01-01');
    expect(vigAmount($this->vig->rowAt('vig_demo', $other, '2026-02-01')))->toBe('900.00');
});

test('un registro que ya rige desde esa fecha no se modifica y un cambio anterior a uno programado se rechaza', function (): void {
    $this->vig->change('vig_demo', $this->rel, ['amount' => '1000.00'], '2026-01-01');
    $this->vig->change('vig_demo', $this->rel, ['amount' => '1100.00'], '2026-06-01');

    try {
        $this->vig->change('vig_demo', $this->rel, ['amount' => '1150.00'], '2026-06-01');
        $this->fail('Debía rechazar el cambio del mismo día.');
    } catch (AyniException $e) {
        expect($e->getStatusCode())->toBe(409)->and($e->getErrorCode())->toBe('overlap')->and($e->getMeta()['reason'])->toBe('same_day');
    }
    try {
        $this->vig->change('vig_demo', $this->rel, ['amount' => '1050.00'], '2026-03-01');
        $this->fail('Debía rechazar el cambio anterior a uno programado.');
    } catch (AyniException $e) {
        expect($e->getStatusCode())->toBe(409)->and($e->getMeta()['reason'])->toBe('future_exists');
    }
    expect(vigAmount($this->vig->rowAt('vig_demo', $this->rel, '2026-06-01')))->toBe('1100.00')
        ->and($this->vig->history('vig_demo', $this->rel))->toHaveCount(2);

    // El modo «replace» existe solo para valores sin historia propia el mismo día (parámetros).
    $this->vig->change('vig_demo', $this->rel, ['amount' => '1120.00'], '2026-06-01', EffectiveDated::SAME_DAY_REPLACE);
    expect(vigAmount($this->vig->rowAt('vig_demo', $this->rel, '2026-06-01')))->toBe('1120.00');
});

test('cerrar la vigencia no borra la fila y deja la relación sin valor desde el día siguiente', function (): void {
    $this->vig->change('vig_demo', $this->rel, ['amount' => '1000.00'], '2026-01-01');
    expect($this->vig->close('vig_demo', $this->rel, '2026-04-30'))->toBeTrue();

    expect(vigAmount($this->vig->rowAt('vig_demo', $this->rel, '2026-04-30')))->toBe('1000.00')
        ->and($this->vig->rowAt('vig_demo', $this->rel, '2026-05-01'))->toBeNull()
        ->and($this->vig->history('vig_demo', $this->rel))->toHaveCount(1)
        ->and($this->vig->close('vig_demo', $this->rel, '2026-06-01'))->toBeFalse();
});

/**
 * Tablas con valid_from/valid_to cuya historia no es «una fila por relación y fecha» (versiones de
 * políticas o catálogos con su propia unicidad). Una tabla nueva con vigencia debe tener EXCLUDE
 * o justificarse aquí.
 */
const VIG_WITHOUT_EXCLUSION = [
    'advance_policies', 'collective_agreement_clauses', 'collective_agreements', 'concept_account_mappings',
    'country_concept_templates', 'expense_policies', 'org_units', 'salary_bands', 'salary_policies',
    'tenant_settings', 'time_policies', 'user_roles', 'vacation_policies',
    // CFG-10: una sola fila por (master_catalog_id, code) por restricción única; no puede haber solape.
    'master_catalog_items',
    // POS-1: valid_from / valid_to es la vigencia de la plaza misma (una fila por posición), no una historia por relación.
    'positions',
];

test('toda tabla con vigencia tiene restricción de exclusión o está justificada', function (): void {
    $missing = collect(DB::select("SELECT c.table_name FROM information_schema.columns c
        JOIN information_schema.columns d ON d.table_schema = c.table_schema AND d.table_name = c.table_name AND d.column_name = 'valid_to'
        JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
        WHERE c.table_schema = 'public' AND c.column_name = 'valid_from'
          AND NOT EXISTS (SELECT 1 FROM pg_constraint k WHERE k.conrelid = ('public.'||c.table_name)::regclass AND k.contype = 'x')"))
        ->pluck('table_name')->diff(VIG_WITHOUT_EXCLUSION)->values()->all();

    expect($missing)->toBe([]);
});
