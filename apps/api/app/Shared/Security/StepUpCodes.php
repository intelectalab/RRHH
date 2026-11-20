<?php

namespace App\Shared\Security;

/**
 * DOC-7 · Código de un solo uso para confirmar una acción sensible (firmar un documento). Lo implementa el
 * módulo de identidad con sus canales (correo o SMS/WhatsApp); en base solo queda su HMAC.
 */
interface StepUpCodes
{
    /**
     * Emite y envía un código al usuario.
     *
     * @return array{channel: string, reference: string, expires_at: string}|null null si el usuario no tiene correo ni teléfono
     */
    public function send(string $userId, string $purpose): ?array;

    /** Verifica y consume el código; cada fallo cuenta como intento. */
    public function verify(string $userId, string $purpose, string $code): bool;
}
