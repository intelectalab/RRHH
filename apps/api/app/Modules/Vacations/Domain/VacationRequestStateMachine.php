<?php

namespace App\Modules\Vacations\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-15 · VAC-3 · Solicitud de vacaciones. En base: draft (borrador), pending (pendiente de jefe),
 * hr_review (en validación de RRHH), approved (aprobada, programada), in_progress (en goce),
 * taken (gozada), rejected, cancelled.
 *
 * - submit: el titular (o su jefe / RRHH a su nombre) envía el borrador; arranca el flujo vacation_request.
 * - register: RRHH registra la solicitud ya aprobada, sin flujo (time.vacation_requests.validate).
 * - approve / reject: los aplica el motor de aprobaciones (ApprovalOutcomeHandler); no tienen ruta
 *   propia: se decide en el centro de aprobaciones, donde también se ven los cruces del equipo (VAC-4).
 * - start / finish: el sistema, cada día (vacations:advance): el goce consume la reserva del libro.
 * - cancel: titular o RRHH antes del inicio del goce; una aprobada devuelve los días al saldo.
 *
 * hr_review (En validación de RRHH) queda en la base para el flujo con dos niveles; el motor recorre los
 * niveles por dentro y devuelve solo la decisión final, así que la solicitud pasa de pending a approved.
 */
final class VacationRequestStateMachine extends StateMachine
{
    /** Acciones que solo ejecuta el sistema (el motor de aprobaciones o la tarea diaria). */
    public const SYSTEM_ACTIONS = ['approve', 'reject', 'start', 'finish'];

    /** Estados que ocupan el calendario del trabajador (no admiten otra solicitud ni ausencia solapada). */
    public const ACTIVE_STATES = ['pending', 'hr_review', 'approved', 'in_progress', 'taken'];

    /** Estados en trámite (aún sin decisión). */
    public const PENDING_STATES = ['pending', 'hr_review'];

    /** Estados en los que las vacaciones rigen o regirán (calendario del equipo, asistencia). */
    public const APPROVED_STATES = ['approved', 'in_progress', 'taken'];

    public function transitions(): array
    {
        return [
            'draft' => ['submit' => 'pending', 'register' => 'approved', 'cancel' => 'cancelled'],
            'pending' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'],
            'hr_review' => ['approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'],
            'approved' => ['start' => 'in_progress', 'cancel' => 'cancelled'],
            'in_progress' => ['finish' => 'taken'],
            'taken' => [],
            'rejected' => [],
            'cancelled' => [],
        ];
    }

    /**
     * Acciones que una persona puede pedir por la API desde el estado actual.
     *
     * @return array<int, string>
     */
    public function manualActions(string $state): array
    {
        return array_values(array_diff($this->allowedActions($state), self::SYSTEM_ACTIONS));
    }
}
