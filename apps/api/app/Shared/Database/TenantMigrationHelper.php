<?php

namespace App\Shared\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

final class TenantMigrationHelper
{
    /**
     * Add standard tenant and audit columns to a blueprint.
     */
    public static function addTenantColumns(Blueprint $table): void
    {
        $table->uuid('tenant_id');
        $table->timestamps();
        $table->softDeletes();
        $table->unsignedInteger('lock_version')->default(1);
    }

    /**
     * Enable and force RLS on a table with the standard tenant_isolation policy.
     */
    public static function enableRls(string $tableName): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE {$tableName} ENABLE ROW LEVEL SECURITY;");
        DB::statement("ALTER TABLE {$tableName} FORCE ROW LEVEL SECURITY;");
        DB::statement("
            CREATE POLICY tenant_isolation ON {$tableName}
              USING      (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
              WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
        ");
    }

    /**
     * Add a composite foreign key (tenant_id, x_id) referencing (tenant_id, id).
     */
    public static function addCompositeForeignKey(
        string $tableName,
        string $columnName,
        string $targetTable,
        string $onDelete = 'RESTRICT',
    ): void {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $constraintName = sprintf('fk_%s_%s', $tableName, $columnName);
        DB::statement("
            ALTER TABLE {$tableName}
              ADD CONSTRAINT {$constraintName}
              FOREIGN KEY (tenant_id, {$columnName})
              REFERENCES {$targetTable} (tenant_id, id)
              ON DELETE {$onDelete};
        ");
    }
}
