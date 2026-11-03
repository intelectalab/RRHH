<?php

namespace App\Modules\CoreHR\Domain;

/**
 * Employee — Entidad de dominio del módulo Core HR.
 * Capa de dominio pura: no depende de Eloquent ni de HTTP.
 * Stub de fase 0; la implementación completa corresponde a las historias EMP-*.
 */
final class Employee
{
    public function __construct(
        public readonly string $id,
        public readonly string $fullName,
    ) {}
}

