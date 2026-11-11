<?php

namespace App\Shared\Database;

use Illuminate\Support\Facades\DB;

final class DataDictionaryGenerator
{
    /**
     * Generate the complete data dictionary in Markdown format.
     */
    public function generateMarkdown(): string
    {
        $tables = $this->getTables();
        $totalTables = count($tables);

        $out = "# Diccionario de Datos — GestiaHR\n\n";
        $out .= sprintf("Generado automáticamente desde el catálogo de la base de datos PostgreSQL. Total de tablas documentadas: **%d**.\n\n", $totalTables);

        foreach ($tables as $tableName) {
            $out .= "### {$tableName}\n\n";

            $columns = $this->getTableColumns($tableName);
            if (empty($columns)) {
                $out .= "*Sin columnas identificadas.*\n\n";

                continue;
            }

            $out .= "| Columna | Tipo | Nulo | Por defecto | Descripción / Restricción |\n";
            $out .= "|---|---|:---:|---|---|\n";

            foreach ($columns as $col) {
                $nullable = $col['is_nullable'] === 'YES' ? 'Sí' : 'No';
                $default = $col['column_default'] !== null ? '`'.str_replace('|', '\\|', $col['column_default']).'`' : '—';
                $extra = $col['description'] ?? '—';

                $out .= sprintf(
                    "| `%s` | `%s` | %s | %s | %s |\n",
                    $col['column_name'],
                    $col['data_type'],
                    $nullable,
                    $default,
                    $extra,
                );
            }

            $out .= "\n";
        }

        return $out;
    }

    /**
     * Get the list of table names in public schema.
     *
     * @return array<string>
     */
    public function getTables(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        /** @var array<object{tablename: string}> $rows */
        $rows = DB::select("
            SELECT tablename
              FROM pg_tables
             WHERE schemaname = 'public'
               AND tablename NOT LIKE 'test_%'
             ORDER BY tablename
        ");

        return array_map(fn (object $row): string => $row->tablename, $rows);
    }

    /**
     * Get columns metadata for a given table.
     *
     * @return array<int, array{column_name: string, data_type: string, is_nullable: string, column_default: ?string, description: ?string}>
     */
    public function getTableColumns(string $table): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }

        $sql = "
            SELECT c.column_name,
                   c.data_type,
                   c.is_nullable,
                   c.column_default,
                   pgd.description
              FROM information_schema.columns c
              LEFT JOIN pg_class pc ON pc.relname = c.table_name
              LEFT JOIN pg_attribute pa ON pa.attrelid = pc.oid AND pa.attname = c.column_name
              LEFT JOIN pg_description pgd ON pgd.objoid = pc.oid AND pgd.objsubid = pa.attnum
             WHERE c.table_schema = 'public'
               AND c.table_name = ?
             ORDER BY c.ordinal_position
        ";

        /** @var array<object{column_name: string, data_type: string, is_nullable: string, column_default: ?string, description: ?string}> $rows */
        $rows = DB::select($sql, [$table]);

        return array_map(fn (object $row): array => [
            'column_name' => $row->column_name,
            'data_type' => $row->data_type,
            'is_nullable' => $row->is_nullable,
            'column_default' => $row->column_default,
            'description' => $row->description,
        ], $rows);
    }

    /**
     * Generate dictionary and save it to the specified path.
     */
    public function saveToFile(?string $path = null): string
    {
        $targetPath = $path;
        if ($targetPath === null) {
            $candidates = [
                '/var/www/docs/diccionario-datos.md',
                base_path('../../docs/diccionario-datos.md'),
                base_path('docs/diccionario-datos.md'),
                storage_path('diccionario-datos.md'),
            ];

            foreach ($candidates as $cand) {
                $dir = dirname($cand);
                if (is_dir($dir) && is_writable($dir)) {
                    $targetPath = $cand;
                    break;
                }
            }

            $targetPath ??= storage_path('diccionario-datos.md');
        }

        $markdown = $this->generateMarkdown();
        file_put_contents($targetPath, $markdown);

        return $targetPath;
    }
}
