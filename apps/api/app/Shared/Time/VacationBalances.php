<?php

namespace App\Shared\Time;

/**
 * VAC-2 · Contrato público del saldo de vacaciones (lo implementa el módulo Vacations).
 *
 * El saldo de una relación laboral es la suma de su libro de movimientos (RN-VAC-02): ningún módulo
 * guarda ni calcula un saldo propio. Las solicitudes (VAC-3) reservan días al aprobarse, los liberan si
 * se cancelan y los consumen al gozarse. Los días van como decimales en texto («7.50»), nunca float.
 *
 * Todas las operaciones son idempotentes por solicitud: repetir reserve/release/consume con la misma
 * solicitud no duplica movimientos.
 */
interface VacationBalances
{
    /**
     * Saldo de la relación a la fecha (por defecto hoy), con columnas y detalle por periodo.
     *
     * @return array{
     *     employment_id: string,
     *     as_of: string,
     *     totals: array{generated: string, adjusted: string, scheduled: string, taken: string, sold: string, settled: string, available: string},
     *     accruing: array{period_id: string, period_start: string, period_end: string, entitled_days: string, accrued_days: string}|null,
     *     advance_available: string,
     *     overdue_days: string,
     *     pending_validation: bool,
     *     periods: list<array<string, mixed>>,
     *     policy: array<string, mixed>
     * }
     */
    public function balance(string $employmentId, ?string $asOf = null): array;

    /**
     * Reglas de la política que aplica a la relación a la fecha (VAC-1): cómputo calendario o hábil,
     * fraccionamiento mínimo, adelanto, venta, validación de RRHH y su origen (política o parámetro legal).
     *
     * @return array<string, mixed>
     */
    public function policyFor(string $employmentId, string $asOf): array;

    /**
     * Reserva («programadas») los días de una solicitud aprobada: consume primero los periodos más
     * antiguos; si no alcanza, hasta $maxAdvanceDays se toman a cuenta del periodo en curso (adelanto).
     * $effectiveOn es el inicio del goce; la reserva descuenta del saldo desde hoy si el goce es futuro.
     *
     * @return list<string> ids de los movimientos creados (o los ya existentes si se repite)
     *
     * @throws InsufficientVacationBalance si los días superan el disponible más el adelanto permitido (RN-VAC-04)
     */
    public function reserve(string $employmentId, string $vacationRequestId, string $days, string $effectiveOn, ?string $userId, string $maxAdvanceDays = '0'): array;

    /** Devuelve al saldo los días reservados por la solicitud (cancelación o rechazo tras aprobar). */
    public function release(string $vacationRequestId, string $reason, ?string $userId): void;

    /** Pasa los días reservados de la solicitud de «programadas» a «tomadas» (inicio del goce). */
    public function consume(string $vacationRequestId, string $effectiveOn, ?string $userId): void;
}
