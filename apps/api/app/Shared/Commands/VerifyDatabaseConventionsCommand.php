<?php

namespace App\Shared\Commands;

use App\Shared\Database\DatabaseConventionsChecker;
use Illuminate\Console\Command;

final class VerifyDatabaseConventionsCommand extends Command
{
    protected $signature = 'db:verify-conventions';

    protected $description = 'Verifica que todas las tablas y claves foráneas cumplan las convenciones de GestiaHR (tenant_id, RLS, FK compuestas y sin borrado en cascada en historia laboral)';

    public function handle(DatabaseConventionsChecker $checker): int
    {
        $this->info('Verificando convenciones del modelo de datos...');

        $violations = $checker->check();

        if (empty($violations)) {
            $this->info('✓ Todas las tablas cumplen las convenciones de GestiaHR:');
            $this->line('  - tenant_id NOT NULL en todas las tablas de negocio');
            $this->line('  - RLS activado y forzado con política tenant_isolation');
            $this->line('  - Claves foráneas compuestas (tenant_id, x_id)');
            $this->line('  - Sin borrado en cascada (ON DELETE CASCADE) sobre tablas de historia laboral');

            return 0;
        }

        $this->error('✗ Se encontraron violaciones a las convenciones del modelo de datos:');

        foreach ($violations as $category => $items) {
            $this->warn(sprintf('Categoría [%s]: %d problemas detectados', $category, count($items)));
            foreach ($items as $item) {
                $this->line('  - '.$item);
            }
        }

        return 1;
    }
}
