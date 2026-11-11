<?php

declare(strict_types=1);

namespace App\Shared\Commands;

use App\Shared\Security\FieldCipher;
use App\Shared\Tenancy\Resolution\TenantDirectory;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SEG-11 · Vuelve a cifrar con la clave activa los campos sensibles cifrados con otra clave
 * (o con el formato anterior de APP_KEY). Recorre organización por organización bajo RLS.
 * Es idempotente: un valor ya cifrado con la clave activa no se toca.
 * Procedimiento completo: docs/seguridad-cifrado-de-campos.md.
 */
class RotateFieldKeysCommand extends Command
{
    protected $signature = 'security:rotate-field-keys {--dry-run : Solo cuenta lo pendiente, no escribe}';

    protected $description = 'Vuelve a cifrar los campos sensibles con la clave activa (rotación de claves)';

    public function handle(TenantDirectory $tenants): int
    {
        $dry = (bool) $this->option('dry-run');
        $active = FieldCipher::activeKeyId();
        /** @var array<string, array<int, string>> $columns */
        $columns = (array) config('security.encrypted_columns', []);
        $totals = ['scanned' => 0, 'pending' => 0, 'rotated' => 0];

        foreach ($tenants->operableTenantIds() as $tenantId) {
            TenancyScope::runAs($tenantId, function () use ($columns, $dry, &$totals): void {
                foreach ($columns as $table => $cols) {
                    foreach ($cols as $col) {
                        DB::table($table)->select(['id', $col])->whereNotNull($col)->orderBy('id')
                            ->chunk(500, function ($rows) use ($table, $col, $dry, &$totals): void {
                                foreach ($rows as $row) {
                                    $totals['scanned']++;
                                    $value = (string) $row->{$col};
                                    if (! FieldCipher::needsRotation($value)) {
                                        continue;
                                    }
                                    $totals['pending']++;
                                    if ($dry) {
                                        continue;
                                    }
                                    // Solo se reemplaza si el valor no cambió mientras tanto.
                                    $updated = DB::table($table)->where('id', $row->id)->where($col, $value)
                                        ->update([$col => FieldCipher::encrypt(FieldCipher::decrypt($value))]);
                                    $totals['rotated'] += $updated;
                                }
                            });
                    }
                }
            });
        }

        $this->info("Clave activa: {$active}");
        $this->line("Revisados: {$totals['scanned']} · Pendientes: {$totals['pending']} · Rotados: {$totals['rotated']}".($dry ? ' (simulación)' : ''));

        return self::SUCCESS;
    }
}
