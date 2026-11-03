<?php

namespace App\Modules\Platform\Domain;

/**
 * PlatformModule — Entidad raíz del módulo de plataforma.
 * Capa de dominio pura: no depende de Eloquent ni de HTTP.
 */
final class PlatformModule
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly bool $active = true,
    ) {}
}
