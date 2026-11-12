<?php

namespace App\Shared\Usage;

/**
 * SAS-6 · Métricas de consumo que se comparan con los límites del plan (tenant_limits.limit_code).
 *
 * Las de tipo «foto» (colaboradores, administradores, empresas, almacenamiento) guardan un valor por día en
 * tenant_usage_counters; las llamadas de API acumulan por mes.
 */
final class UsageMetrics
{
    /** Colaboradores con relación laboral activa (RN-SAS-02). */
    public const EMPLOYEES = 'employees';

    /** Usuarios no desactivados con el rol de administrador de la organización. */
    public const ADMINS = 'admins';

    /** Empresas activas (RN-TEN-07). */
    public const LEGAL_ENTITIES = 'legal_entities';

    /** Almacenamiento usado en MB (RN-SAS-03). */
    public const STORAGE_MB = 'storage_mb';

    /** Llamadas a la API del mes en curso. */
    public const API_CALLS = 'api_calls';

    /** Orden en que se muestran. */
    public const ALL = [self::EMPLOYEES, self::ADMINS, self::LEGAL_ENTITIES, self::STORAGE_MB, self::API_CALLS];

    /** Métricas que se miden por mes (el resto, por día). */
    public const MONTHLY = [self::API_CALLS];

    public static function periodStart(string $metric): string
    {
        $today = now()->toImmutable();

        return in_array($metric, self::MONTHLY, true) ? $today->startOfMonth()->toDateString() : $today->toDateString();
    }
}
