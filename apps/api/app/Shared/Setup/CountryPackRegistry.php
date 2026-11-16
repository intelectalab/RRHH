<?php

namespace App\Shared\Setup;

/**
 * IMP-4 · Registro de las partes de los paquetes de país. Cada módulo registra la suya en su proveedor.
 */
final class CountryPackRegistry
{
    /** @var array<string, CountryPackComponent> */
    private array $components = [];

    public function register(CountryPackComponent $component): void
    {
        $this->components[$component->code()] = $component;
    }

    public function get(string $code): ?CountryPackComponent
    {
        return $this->components[$code] ?? null;
    }
}
