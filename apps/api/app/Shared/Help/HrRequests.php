<?php

namespace App\Shared\Help;

/**
 * AYU-6 · Puerto para que el centro de ayuda derive una consulta a RRHH como solicitud (ESS-5) sin conocer el
 * módulo de solicitudes. La solicitud lleva el enlace a la consulta derivada y su estado se lee de vuelta.
 */
interface HrRequests
{
    /**
     * Abre la solicitud a RRHH con la pregunta y los artículos consultados.
     *
     * @return array{id: string, number: string}
     */
    public function open(string $escalationId, string $userId, ?string $employmentId, string $subject, string $description): array;

    /**
     * Solicitudes abiertas desde consultas derivadas, por id de consulta.
     *
     * @param  list<string>  $escalationIds
     * @return array<string, array{id: string, number: string, status: string}>
     */
    public function forEscalations(array $escalationIds): array;
}
