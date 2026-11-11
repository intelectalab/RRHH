<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas mínimas para la calculadora de referencia.
 *
 * La fuente de verdad del esquema es kit/database/schema.sql. Esta migración traduce solo las tablas
 * que usa la calculadora, con los mismos nombres y tipos: identificadores UUID v7 (ADR 0001), montos
 * numeric(14,2), tasas en porcentaje numeric(7,4) y parámetros con vigencia (M-PAR).
 *
 * «employees» es una simplificación para el ejemplo. En el producto, los datos del trabajador están en
 * employments, employment_compensations, employment_labor_regimes y employment_pension_affiliations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labor_regimes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();               // pe_general, pe_micro, pe_pequena…
            $t->char('country_code', 2);
            $t->string('name');
            $t->string('kind');                         // general, micro, small, construction, agrarian, training
            $t->timestampsTz();
        });

        // Parámetros legales con vigencia. Equivale a la tabla llave-valor «payroll_parameters» del
        // requerimiento, pero cada valor tiene fecha de inicio y fin, fuente y marca de verificado.
        // labor_regime_code nulo = valor global del país; con código = valor propio de ese régimen.
        Schema::create('legal_parameters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('country_code', 2);
            $t->string('labor_regime_code')->nullable();
            $t->string('code');
            $t->string('name');
            $t->decimal('value_numeric', 18, 6)->nullable();
            $t->jsonb('value_json')->nullable();
            $t->string('unit');
            $t->char('currency', 3)->nullable();
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->string('legal_source')->nullable();
            $t->boolean('verified')->default(false);
            $t->text('notes')->nullable();
            $t->timestampsTz();
            $t->foreign('labor_regime_code')->references('code')->on('labor_regimes');
            $t->index(['country_code', 'code']);
        });

        Schema::create('pension_funds', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code')->unique();
            $t->char('country_code', 2);
            $t->string('name');
            $t->string('kind');                         // private (AFP) o public (ONP)
            $t->timestampsTz();
        });

        // Tasas por AFP con vigencia, en porcentaje. Se cargan de la fuente oficial cada periodo.
        Schema::create('pension_fund_rates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('pension_fund_id')->constrained('pension_funds');
            $t->decimal('contribution_rate', 7, 4)->nullable();
            $t->decimal('commission_flow_rate', 7, 4)->nullable();
            $t->decimal('commission_mixed_rate', 7, 4)->nullable();
            $t->decimal('insurance_premium_rate', 7, 4)->nullable();
            $t->decimal('max_insurable_remuneration', 14, 2)->nullable();
            $t->string('source')->nullable();
            $t->boolean('verified')->default(false);
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->timestampsTz();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->string('full_name');
            $t->decimal('base_salary', 14, 2);
            $t->boolean('has_family_allowance')->default(false); // hijos que dan derecho (Ley 25129)
            $t->string('labor_regime_code');
            $t->string('health_scheme')->default('essalud');    // essalud, eps, sis
            $t->string('pension_system');                       // afp, onp, none
            $t->string('pension_fund_code')->nullable();
            $t->string('commission_scheme')->nullable();        // flow, mixed
            $t->date('hire_date')->nullable();                  // prorrateo del mes de ingreso y liquidación
            $t->date('termination_date')->nullable();           // prorrateo del mes de cese y liquidación
            $t->string('construction_category')->nullable();    // operario, oficial, peon (construcción civil)
            $t->boolean('agrarian_prorated_benefits')->default(false); // agrario: gratificación y CTS prorrateadas
            $t->string('agrarian_tier')->nullable();            // agrario: tramo de EsSalud de la razón social (A o B)
            $t->timestampsTz();
            $t->foreign('labor_regime_code')->references('code')->on('labor_regimes');
            $t->foreign('pension_fund_code')->references('code')->on('pension_funds');
            $t->unique(['tenant_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement('ALTER TABLE legal_parameters ADD CONSTRAINT ck_legal_parameters_vigencia CHECK (valid_to IS NULL OR valid_to >= valid_from)');
            DB::statement("ALTER TABLE legal_parameters ADD CONSTRAINT ex_legal_parameters_vigencia EXCLUDE USING gist (country_code WITH =, (COALESCE(labor_regime_code, '')) WITH =, code WITH =, daterange(valid_from, valid_to, '[]') WITH &&)");
            DB::statement("ALTER TABLE pension_fund_rates ADD CONSTRAINT ex_pension_fund_rates_vigencia EXCLUDE USING gist (pension_fund_id WITH =, daterange(valid_from, valid_to, '[]') WITH &&)");
            DB::statement("ALTER TABLE employees ADD CONSTRAINT ck_employees_pension_system CHECK (pension_system IN ('afp','onp','none'))");
            DB::statement("ALTER TABLE employees ADD CONSTRAINT ck_employees_health_scheme CHECK (health_scheme IN ('essalud','eps','sis'))");
            DB::statement('ALTER TABLE employees ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE employees FORCE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY tenant_isolation ON employees USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)");
        }
    }

    public function down(): void
    {
        foreach (['employees', 'pension_fund_rates', 'pension_funds', 'legal_parameters', 'labor_regimes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
