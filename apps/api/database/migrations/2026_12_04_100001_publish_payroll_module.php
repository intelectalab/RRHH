<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * QA-17 · La planilla del régimen general y MYPE quedó terminada en el primer corte comercial: el módulo payroll
 * pasa a estar a la venta (WEB-2: el sitio lo muestra disponible y no «Próximamente»). Después se publica o
 * despublica desde la consola, como cualquier módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::update("UPDATE modules SET is_published = true, published_at = now() WHERE code = 'payroll' AND is_published = false");
    }

    public function down(): void
    {
        // Sin reversión: despublicar es una decisión de la consola (POST /platform/modules/{code}/unpublish).
    }
};
