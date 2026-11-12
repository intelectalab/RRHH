<?php

namespace App\Modules\Platform\Application;

use Illuminate\Support\Facades\DB;

/**
 * Configuración global de la plataforma (tabla platform_settings, sin organización). Sus valores
 * los mantiene la consola; si una clave no está, vale el valor por defecto de config/platform.php.
 */
final class PlatformSettings
{
    public function value(string $key): mixed
    {
        $raw = DB::table('platform_settings')->where('key', $key)->value('value');
        if ($raw !== null) {
            return json_decode((string) $raw, true);
        }

        // Las claves llevan puntos: se buscan en el arreglo, no con la notación de puntos de config().
        $defaults = (array) config('platform.settings', []);

        return $defaults[$key] ?? null;
    }

    /**
     * Días de conservación de los datos tras la baja (RN-SAS-10), por país con «default» de respaldo.
     */
    public function retentionDays(string $countryCode): int
    {
        $map = $this->value('tenants.retention_days_after_cancel');
        if (! is_array($map)) {
            return 0;
        }

        return max(0, (int) ($map[$countryCode] ?? $map['default'] ?? 0));
    }

    /**
     * Días de solo lectura de un módulo retirado antes de ocultarse (RN-SAS-06).
     */
    public function moduleReadOnlyDays(): int
    {
        return max(0, (int) $this->value('modules.read_only_transition_days'));
    }

    /**
     * Días de gracia, con la organización en solo lectura, tras vencer la prueba sin conversión (RN-SAS-04).
     */
    public function trialGraceDays(): int
    {
        return max(0, (int) $this->value('subscriptions.trial_grace_days'));
    }
}
