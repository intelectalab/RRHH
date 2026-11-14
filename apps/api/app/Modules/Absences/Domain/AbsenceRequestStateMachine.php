<?php

namespace App\Modules\Absences\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-16 · AUS-2 · Ausencia. En base: draft (borrador), pending (Solicitada, en aprobación),
 * pending_document (Pendiente de sustento), approved, in_progress, completed, rejected, cancelled.
 *
 * - submit: el colaborador o su jefe envía el borrador; si el tipo exige sustento y falta, queda
 *   «pendiente de sustento» (request_document) por el plazo del tipo (RN-AUS-01).
 * - register: RRHH la registra directamente, sin flujo (time.absences.create_for_others).
 * - attach_document / confirm_document: el sustento llega; si vino por el flujo vuelve a «Solicitada»
 *   y arranca la aprobación; si la registró RRHH queda aprobada.
 * - approve / reject: los aplica el motor de aprobaciones (ApprovalOutcomeHandler); no tienen ruta.
 * - start / finish / expire_document: el sistema, cada día (absences:advance).
 * - cancel: titular o RRHH antes del inicio.
 */
final class AbsenceRequestStateMachine extends StateMachine
{
    /** Acciones que solo ejecuta el sistema (el motor de aprobaciones o la tarea diaria). */
    public const SYSTEM_ACTIONS = ['approve', 'reject', 'request_document', 'attach_document', 'confirm_document', 'expire_document', 'start', 'finish'];

    /** Estados que ocupan el calendario del trabajador (no admiten otra ausencia solapada). */
    public const ACTIVE_STATES = ['pending', 'pending_document', 'approved', 'in_progress', 'completed'];

    /** Estados en los que la ausencia ya rige o regirá (calendario del equipo y asistencia). */
    public const APPROVED_STATES = ['approved', 'in_progress', 'completed'];

    public function transitions(): array
    {
        return [
            'draft' => ['submit' => 'pending', 'request_document' => 'pending_document', 'register' => 'approved', 'cancel' => 'cancelled'],
            'pending' => ['approve' => 'approved', 'reject' => 'rejected', 'request_document' => 'pending_document', 'cancel' => 'cancelled'],
            'pending_document' => ['attach_document' => 'pending', 'confirm_document' => 'approved', 'expire_document' => 'rejected', 'cancel' => 'cancelled'],
            'approved' => ['start' => 'in_progress', 'finish' => 'completed', 'cancel' => 'cancelled'],
            'in_progress' => ['finish' => 'completed'],
            'completed' => [],
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
