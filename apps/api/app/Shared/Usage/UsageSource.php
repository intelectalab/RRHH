<?php

namespace App\Shared\Usage;

/**
 * SAS-6 · Fuente de una métrica de consumo. Cada módulo registra la suya (el módulo dueño de los datos
 * sabe contarlos); la medición se hace siempre dentro de la organización activa.
 */
interface UsageSource
{
    /** Código de la métrica (UsageMetrics::*), igual al limit_code de tenant_limits. */
    public function metric(): string;

    /** Consumo actual en centésimas de la unidad (100 = 1 colaborador, 1 MB…): sin flotantes. */
    public function measure(): int;
}
