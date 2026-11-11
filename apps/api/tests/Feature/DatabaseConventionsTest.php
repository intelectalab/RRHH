<?php

use App\Shared\Database\DatabaseConventionsChecker;
use App\Shared\Database\DataDictionaryGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('database conventions pass on existing application schema', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Database conventions checks require PostgreSQL.');
    }

    $exitCode = Artisan::call('db:verify-conventions');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Todas las tablas cumplen las convenciones');
});

test('checker detects business table without tenant_id', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Database conventions checks require PostgreSQL.');
    }

    $checker = new DatabaseConventionsChecker;

    // Create a rogue table without tenant_id
    DB::statement('CREATE TABLE rogue_table (id serial PRIMARY KEY, name text);');

    try {
        $missingTenant = $checker->checkMissingTenantId();
        expect($missingTenant)->toContain('rogue_table');
    } finally {
        DB::statement('DROP TABLE IF EXISTS rogue_table;');
    }
});

test('checker detects business table without forced row level security', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Database conventions checks require PostgreSQL.');
    }

    $checker = new DatabaseConventionsChecker;

    // Create a table with tenant_id but WITHOUT RLS
    DB::statement('CREATE TABLE unsecure_table (id serial PRIMARY KEY, tenant_id uuid NOT NULL, name text);');

    try {
        $missingRls = $checker->checkMissingOrUnforcedRls();
        expect($missingRls)->toContain('unsecure_table');
    } finally {
        DB::statement('DROP TABLE IF EXISTS unsecure_table;');
    }
});

test('checker detects non-composite foreign key between tenant tables', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Database conventions checks require PostgreSQL.');
    }

    $checker = new DatabaseConventionsChecker;

    // Create two tenant tables with RLS and a SIMPLE foreign key (id only, missing tenant_id)
    DB::unprepared('
        CREATE TABLE parent_tenant_table (
            id serial PRIMARY KEY,
            tenant_id uuid NOT NULL
        );
        ALTER TABLE parent_tenant_table ENABLE ROW LEVEL SECURITY;
        ALTER TABLE parent_tenant_table FORCE ROW LEVEL SECURITY;
        CREATE POLICY tenant_isolation ON parent_tenant_table USING (true) WITH CHECK (true);

        CREATE TABLE child_tenant_table (
            id serial PRIMARY KEY,
            tenant_id uuid NOT NULL,
            parent_id integer REFERENCES parent_tenant_table(id)
        );
        ALTER TABLE child_tenant_table ENABLE ROW LEVEL SECURITY;
        ALTER TABLE child_tenant_table FORCE ROW LEVEL SECURITY;
        CREATE POLICY tenant_isolation ON child_tenant_table USING (true) WITH CHECK (true);
    ');

    try {
        $nonComposite = $checker->checkNonCompositeForeignKeys();
        $violating = array_filter(
            $nonComposite,
            fn (string $c) => str_contains($c, 'child_tenant_table -> parent_tenant_table'),
        );
        expect($violating)->not->toBeEmpty();
    } finally {
        DB::unprepared('
            DROP TABLE IF EXISTS child_tenant_table;
            DROP TABLE IF EXISTS parent_tenant_table;
        ');
    }
});

test('checker detects cascade delete targeting employment history tables', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Database conventions checks require PostgreSQL.');
    }

    $checker = new DatabaseConventionsChecker;

    // Create a rogue table with ON DELETE CASCADE to employments
    DB::statement('
        CREATE TABLE rogue_history_cascade (
            id serial PRIMARY KEY,
            tenant_id uuid NOT NULL,
            employment_id uuid NOT NULL,
            CONSTRAINT fk_rogue_cascade FOREIGN KEY (tenant_id, employment_id)
                REFERENCES employments(tenant_id, id) ON DELETE CASCADE
        );
    ');

    try {
        $cascadeViolations = $checker->checkCascadeDeleteOnHistory();
        $violating = array_filter(
            $cascadeViolations,
            fn (string $c) => str_contains($c, 'rogue_history_cascade -> employments'),
        );
        expect($violating)->not->toBeEmpty();
    } finally {
        DB::statement('DROP TABLE IF EXISTS rogue_history_cascade;');
    }
});

test('data dictionary generator produces markdown documentation with all tables and columns', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Data dictionary generation requires PostgreSQL.');
    }

    $generator = new DataDictionaryGenerator;
    $markdown = $generator->generateMarkdown();

    expect($markdown)->toContain('# Diccionario de Datos — GestiaHR');
    expect($markdown)->toContain('### employments');
    expect($markdown)->toContain('`tenant_id`');
    expect($markdown)->toContain('### tenants');

    $tmpPath = tempnam(sys_get_temp_dir(), 'ayni_dict_');
    assert(is_string($tmpPath));

    $saved = $generator->saveToFile($tmpPath);
    expect(file_exists($saved))->toBeTrue();

    @unlink($tmpPath);
});
