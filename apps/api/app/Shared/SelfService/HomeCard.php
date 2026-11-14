<?php

namespace App\Shared\SelfService;

/**
 * ESS-1 · Contrato común para que cada módulo aporte una tarjeta al inicio del colaborador.
 *
 * - RN-ESS-01: la tarjeta solo recibe la relación laboral propia del usuario (la resuelve el portal);
 *   nunca un identificador que el cliente pueda elegir libremente.
 * - RN-ESS-02: el registro solo consulta tarjetas cuyo módulo está contratado y cuyo permiso tiene el usuario.
 */
interface HomeCard
{
    /** Clave estable de la tarjeta (vacations, absences, profile…). Ordena y nombra el bloque en la web. */
    public function key(): string;

    /** Permiso que habilita la tarjeta; su módulo debe estar contratado. */
    public function permission(): string;

    /** Orden de la tarjeta en el inicio (menor primero). */
    public function order(): int;

    /**
     * Datos de la tarjeta para la relación laboral propia, o null si no hay nada que mostrar.
     *
     * @return array{href: string, data: array<string, mixed>, pending?: int}|null
     */
    public function card(string $userId, string $employmentId): ?array;
}
