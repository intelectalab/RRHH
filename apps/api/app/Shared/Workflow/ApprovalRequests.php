<?php

namespace App\Shared\Workflow;

/**
 * POS-3 · Puerto para que un módulo pida una aprobación al motor (WFL-2) sin importar sus clases:
 * el módulo de flujos lo implementa y lo registra en su ServiceProvider.
 *
 * El resultado vuelve al módulo por su ApprovalOutcomeHandler (en la misma transacción) y por el outbox.
 */
interface ApprovalRequests
{
    /**
     * Inicia la aprobación con el flujo publicado para el tipo de trámite. Lanza una regla de negocio
     * (RN-WFL-01) si no hay flujo activo para ese tipo.
     *
     * @param  array<string, mixed>  $context  datos para las condiciones del flujo (empresa, monto, fuera de presupuesto…)
     * @return string id de la solicitud de aprobación
     */
    public function start(string $subjectType, string $subjectId, string $requesterUserId, array $context = []): string;

    /** Anula los pasos pendientes (el solicitante canceló el trámite). No hace nada si ya terminó. */
    public function cancel(string $approvalRequestId, ?string $reason): void;

    /**
     * Estado y nivel actual de la aprobación para mostrarlos en el módulo de origen.
     *
     * @return array{id: string, status: string, level: int|null, pending_approvers: list<string>}|null
     */
    public function summary(string $approvalRequestId): ?array;
}
