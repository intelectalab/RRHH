<?php

namespace App\Shared\Database;

use Illuminate\Support\Facades\DB;

final class DatabaseConventionsChecker
{
    /**
     * Platform tables exempt from multi-tenant RLS isolation.
     *
     * @var array<string>
     */
    public const PLATFORM_TABLES = [
        'addons',
        'addon_modules',
        'help_article_versions',
        'help_articles',
        'web_chat_sessions',
        'web_complaints',
        'web_demo_bookings',
        'web_leads',
        'web_product_modules',
        'web_products',
        'web_signups',
        'backup_restores',
        'backup_runs',
        'compliance_obligation_templates',
        'countries',
        'country_concept_templates',
        'country_document_templates',
        'country_holidays',
        'country_pack_versions',
        'country_packs',
        'currencies',
        'feature_flag_rules',
        'feature_flags',
        'integration_connectors',
        'labor_regimes',
        'legal_parameters',
        'master_catalog_items',
        'master_catalogs',
        'metric_definitions',
        'module_dependencies',
        'modules',
        'parameter_definitions',
        'partner_firm_tenants',
        'partner_firm_users',
        'partner_firms',
        'pe_parametric_items',
        'pe_parametric_tables',
        'pension_fund_rates',
        'pension_funds',
        'permissions',
        'plan_features',
        'plan_limits',
        'plan_modules',
        'plans',
        'platform_audit_events',
        'platform_incidents',
        'platform_sessions',
        'platform_settings',
        'platform_usage_snapshots',
        'platform_users',
        'saas_invoice_lines',
        'saas_invoices',
        'statutory_file_layouts',
        'subscription_addons',
        'subscriptions',
        'support_ticket_messages',
        'support_tickets',
        'system_role_permissions',
        'system_roles',
        'tenant_domains',
        'tenant_status_events',
        'tenants',
        // Laravel infrastructure tables
        'migrations',
        'jobs',
        'job_batches',
        'failed_jobs',
        'cache',
        'cache_locks',
        'sessions',
    ];

    /**
     * Employment history tables where ON DELETE CASCADE is strictly prohibited.
     *
     * @var array<string>
     */
    public const EMPLOYMENT_HISTORY_TABLES = [
        'employments',
        'compensation_history',
        'position_history',
        'contract_history',
        'employment_status_history',
        'work_schedule_assignments',
        'leaves',
        'time_punches',
        'time_punch_events',
        'absence_requests',
        'payslips',
        'payroll_runs',
    ];

    /**
     * Run all convention checks and return violations grouped by category.
     *
     * @return array<string, array<string>>
     */
    public function check(): array
    {
        $violations = [
            'missing_tenant_id' => $this->checkMissingTenantId(),
            'missing_or_unforced_rls' => $this->checkMissingOrUnforcedRls(),
            'missing_isolation_policy' => $this->checkMissingIsolationPolicy(),
            'non_composite_foreign_keys' => $this->checkNonCompositeForeignKeys(),
            'cascade_delete_on_employment_history' => $this->checkCascadeDeleteOnHistory(),
        ];

        return array_filter($violations, fn (array $items) => count($items) > 0);
    }

    public function isValid(): bool
    {
        return count($this->check()) === 0;
    }

    /**
     * Verify that all business tables have a tenant_id column.
     *
     * @return array<string> List of offending tables
     */
    public function checkMissingTenantId(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $quotedPlatform = implode("', '", self::PLATFORM_TABLES);

        $sql = "
            SELECT c.relname
              FROM pg_class c
              JOIN pg_namespace ns ON ns.oid = c.relnamespace AND ns.nspname = 'public'
             WHERE c.relkind IN ('r', 'p')
               AND NOT c.relispartition  -- las particiones heredan de su tabla madre (A-06)
               AND c.relname NOT IN ('{$quotedPlatform}')
               AND c.relname NOT LIKE 'test_%'
               AND NOT EXISTS (
                   SELECT 1
                     FROM pg_attribute a
                    WHERE a.attrelid = c.oid
                      AND a.attname = 'tenant_id'
                      AND a.attnotnull
               )
             ORDER BY c.relname
        ";

        /** @var array<object{relname: string}> $rows */
        $rows = DB::select($sql);

        return array_map(fn (object $row): string => $row->relname, $rows);
    }

    /**
     * Verify that all tenant tables have RLS enabled and FORCED.
     *
     * @return array<string> List of offending tables
     */
    public function checkMissingOrUnforcedRls(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $quotedPlatform = implode("', '", self::PLATFORM_TABLES);

        $sql = "
            SELECT c.relname
              FROM pg_class c
              JOIN pg_namespace ns ON ns.oid = c.relnamespace AND ns.nspname = 'public'
             WHERE c.relkind IN ('r', 'p')
               AND NOT c.relispartition  -- las particiones heredan de su tabla madre (A-06)
               AND c.relname NOT IN ('{$quotedPlatform}')
               AND c.relname NOT LIKE 'test_%'
               AND NOT (c.relrowsecurity AND c.relforcerowsecurity)
             ORDER BY c.relname
        ";

        /** @var array<object{relname: string}> $rows */
        $rows = DB::select($sql);

        return array_map(fn (object $row): string => $row->relname, $rows);
    }

    /**
     * Verify that all tenant tables have the tenant_isolation policy.
     *
     * @return array<string> List of offending tables
     */
    public function checkMissingIsolationPolicy(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $quotedPlatform = implode("', '", self::PLATFORM_TABLES);

        $sql = "
            SELECT c.relname
              FROM pg_class c
              JOIN pg_namespace ns ON ns.oid = c.relnamespace AND ns.nspname = 'public'
             WHERE c.relkind IN ('r', 'p')
               AND NOT c.relispartition  -- las particiones heredan de su tabla madre (A-06)
               AND c.relname NOT IN ('{$quotedPlatform}')
               AND c.relname NOT LIKE 'test_%'
               AND NOT EXISTS (
                   SELECT 1
                     FROM pg_policy p
                    WHERE p.polrelid = c.oid
                      AND p.polname = 'tenant_isolation'
                      AND p.polcmd = '*'
                      AND p.polpermissive
                      AND p.polwithcheck IS NOT NULL
               )
             ORDER BY c.relname
        ";

        /** @var array<object{relname: string}> $rows */
        $rows = DB::select($sql);

        return array_map(fn (object $row): string => $row->relname, $rows);
    }

    /**
     * Verify that all foreign keys between two tenant tables are composite (tenant_id, x_id).
     *
     * @return array<string> List of offending constraints
     */
    public function checkNonCompositeForeignKeys(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $sql = "
            SELECT k.conname, c1.relname AS source_table, c2.relname AS target_table
              FROM pg_constraint k
              JOIN pg_class c1 ON c1.oid = k.conrelid
              JOIN pg_class c2 ON c2.oid = k.confrelid
              JOIN pg_policy p1 ON p1.polrelid = k.conrelid AND p1.polname = 'tenant_isolation'
              JOIN pg_policy p2 ON p2.polrelid = k.confrelid AND p2.polname = 'tenant_isolation'
             WHERE k.contype = 'f'
               AND NOT (
                 EXISTS (
                     SELECT 1
                       FROM pg_attribute a
                      WHERE a.attrelid = k.conrelid
                        AND a.attnum = k.conkey[1]
                        AND a.attname = 'tenant_id'
                 )
                 AND EXISTS (
                     SELECT 1
                       FROM pg_attribute a
                      WHERE a.attrelid = k.confrelid
                        AND a.attnum = k.confkey[1]
                        AND a.attname = 'tenant_id'
                 )
               )
             ORDER BY k.conname
        ";

        /** @var array<object{conname: string, source_table: string, target_table: string}> $rows */
        $rows = DB::select($sql);

        return array_map(
            fn (object $row): string => sprintf('%s (%s -> %s)', $row->conname, $row->source_table, $row->target_table),
            $rows,
        );
    }

    /**
     * Verify that no foreign key targeting employment history tables has ON DELETE CASCADE.
     *
     * @return array<string> List of offending foreign keys
     */
    public function checkCascadeDeleteOnHistory(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $quotedHistory = implode("', '", self::EMPLOYMENT_HISTORY_TABLES);

        $sql = "
            SELECT k.conname, c1.relname AS source_table, c2.relname AS target_table
              FROM pg_constraint k
              JOIN pg_class c1 ON c1.oid = k.conrelid
              JOIN pg_class c2 ON c2.oid = k.confrelid
             WHERE k.contype = 'f'
               AND k.confdeltype = 'c'
               AND c2.relname IN ('{$quotedHistory}')
             ORDER BY k.conname
        ";

        /** @var array<object{conname: string, source_table: string, target_table: string}> $rows */
        $rows = DB::select($sql);

        return array_map(
            fn (object $row): string => sprintf('%s (%s -> %s)', $row->conname, $row->source_table, $row->target_table),
            $rows,
        );
    }
}
