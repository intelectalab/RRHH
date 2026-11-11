<?php

namespace App\Shared\Exports;

/**
 * SEG-12 · Registro de listados exportables. Cada módulo registra los suyos en su proveedor.
 */
final class ExportRegistry
{
    /** @var array<string, ExportSource> */
    private array $sources = [];

    public function register(ExportSource $source): void
    {
        $this->sources[$source->resource()] = $source;
    }

    public function get(string $resource): ?ExportSource
    {
        return $this->sources[$resource] ?? null;
    }

    /**
     * Convierte un valor de campo personalizado o de la API en texto de celda.
     */
    public static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
