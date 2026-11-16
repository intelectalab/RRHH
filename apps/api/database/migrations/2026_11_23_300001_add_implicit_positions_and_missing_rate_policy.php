<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POS-6 · Posiciones implícitas (ingreso sin posición con la política desactivada).
 * CFG-7 · RN-CFG-07 · Qué hacer si falta el tipo de cambio de la fecha.
 * Idempotente: corre igual sobre una base creada con el esquema anterior o con el nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE positions ADD COLUMN IF NOT EXISTS is_implicit boolean NOT NULL DEFAULT false');
        DB::statement(<<<'SQL'
            COMMENT ON TABLE positions IS 'Posición o plaza presupuestada (C-02): una silla concreta con puesto de catálogo, unidad, sede, centro de costo, posición jefe y banda de referencia (POS-1). Admite un ocupante, o tantos como seats si es compartida (RN-POS-02). Ocupada o vacante se deriva de las asignaciones vigentes (POS-2). valid_from / valid_to: vigencia de la plaza. is_implicit: la creó el sistema al registrar un ingreso sin posición con la política «contratar solo contra posición» desactivada (POS-6).'
            SQL);

        DB::statement(<<<'SQL'
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('org.tipo_cambio_faltante', 'base', 'Sin tipo de cambio de la fecha', 'RN-CFG-07 · Si falta el tipo de cambio de la fecha al convertir o consolidar: usar el último anterior disponible (el resultado se marca como «tipo de cambio referencial») o bloquear la conversión.', 'select', '"use_previous"'::jsonb, ARRAY['organization'], '["use_previous", "block"]'::jsonb)
            ON CONFLICT (code) DO NOTHING
            SQL);
        DB::statement(<<<'SQL'
            UPDATE parameter_definitions SET description = 'POS-6 · RN-POS-01 · Activa: un ingreso sin una posición aprobada y libre se rechaza. Desactivada: el ingreso sin posición crea una posición implícita para la persona, así la dotación siempre cuenta plazas.'
            WHERE code = 'posiciones.contratar_solo_con_posicion'
            SQL);
    }

    public function down(): void {}
};
