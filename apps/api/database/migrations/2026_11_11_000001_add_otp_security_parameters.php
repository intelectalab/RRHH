<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEG-2 · Ingreso con documento y código por mensaje: vigencia, intentos y espera entre envíos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            INSERT INTO parameter_definitions (code, module_code, name, description, data_type, default_value, allowed_scopes, options) VALUES
              ('seguridad.otp_minutos', 'base', 'Vigencia del código por mensaje (minutos)', 'Minutos que dura el código de ingreso o de activación enviado por SMS o WhatsApp.', 'integer', '5'::jsonb, ARRAY['organization'], '{"min": 2, "max": 15}'::jsonb),
              ('seguridad.otp_intentos', 'base', 'Intentos por código', 'Intentos fallidos que admite un código antes de anularse.', 'integer', '5'::jsonb, ARRAY['organization'], '{"min": 3, "max": 10}'::jsonb),
              ('seguridad.otp_reenvio_segundos', 'base', 'Espera para pedir otro código (segundos)', 'Tiempo mínimo entre dos envíos de código al mismo usuario.', 'integer', '60'::jsonb, ARRAY['organization'], '{"min": 30, "max": 600}'::jsonb)
            ON CONFLICT (code) DO NOTHING;
            SQL);
    }

    public function down(): void {}
};
