<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CFG-1 · Textos de ayuda de los parámetros de notificaciones sin referencias internas, y opciones
 * de «avisos obligatorios» para elegirlas en el centro de configuración (igual que database/schema.sql).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $rows = [
            'notificaciones.silencio_desde' => ['Hora local desde la que los avisos no urgentes por correo se envían al terminar el silencio. Vacío: sin silencio.', null],
            'notificaciones.silencio_hasta' => ['Hora local en que se entregan los avisos que esperaron por el silencio.', null],
            'notificaciones.obligatorias' => ['Tipos de aviso que el usuario no puede desactivar en la aplicación, además de los del producto.',
                '["approval.pending", "approval.reminder", "approval.escalated", "approval.approved", "approval.rejected", "task.assigned", "task.overdue"]'],
        ];
        foreach ($rows as $code => [$description, $options]) {
            DB::table('parameter_definitions')->where('code', $code)->update(array_filter(['description' => $description, 'options' => $options]));
        }
    }

    public function down(): void {}
};
