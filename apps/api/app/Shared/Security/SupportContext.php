<?php

namespace App\Shared\Security;

/**
 * SAS-8 · RN-SAS-08 · Sesión de soporte activa en la petición en curso.
 *
 * La fija el guard de sesión de la organización cuando la sesión fue abierta por el soporte de GestiaHR
 * (user_sessions.impersonated_by_platform_user_id). Con ella:
 * - la auditoría de la organización marca cada acción como «platform_support»;
 * - los permisos sensibles (sueldos, salud, denuncias…) no surten efecto salvo autorización «full» del cliente.
 */
final class SupportContext
{
    /** @var array{session_id: string, platform_user_id: string, agent_name: string, allow_sensitive: bool, expires_at: string}|null */
    private ?array $current = null;

    /**
     * @param  array{session_id: string, platform_user_id: string, agent_name: string, allow_sensitive: bool, expires_at: string}  $session
     */
    public function enter(array $session): void
    {
        $this->current = $session;
    }

    public function clear(): void
    {
        $this->current = null;
    }

    public function active(): bool
    {
        return $this->current !== null;
    }

    public function allowsSensitive(): bool
    {
        return $this->current === null || $this->current['allow_sensitive'];
    }

    /**
     * @return array{session_id: string, platform_user_id: string, agent_name: string, allow_sensitive: bool, expires_at: string}|null
     */
    public function current(): ?array
    {
        return $this->current;
    }

    /**
     * Ejecuta $callback como si la petición fuera del soporte (la consola deja constancia en la organización).
     *
     * @template T
     *
     * @param  array{session_id: string, platform_user_id: string, agent_name: string, allow_sensitive: bool, expires_at: string}  $session
     * @param  callable(): T  $callback
     * @return T
     */
    public function during(array $session, callable $callback): mixed
    {
        $previous = $this->current;
        $this->current = $session;
        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }
}
