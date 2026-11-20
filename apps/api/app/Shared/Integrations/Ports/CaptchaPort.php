<?php

namespace App\Shared\Integrations\Ports;

/**
 * WEB-5 a WEB-7 · Verificación antirrobot de los formularios públicos del sitio. El proveedor real se elige después;
 * fuera de producción se usa la implementación falsa.
 */
interface CaptchaPort
{
    /**
     * @return bool true si el token prueba que lo envió una persona
     */
    public function verify(string $token, ?string $ip): bool;

    public function providerName(): string;
}
