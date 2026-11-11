<?php

namespace App\Shared\Imports;

/**
 * Resultado de validar una fila: datos interpretados, errores (impiden importar la fila) y
 * advertencias (la fila se importa igual). Cada problema lleva columna, código y mensaje traducido.
 */
final class RowCheck
{
    /** @var array<int, array{column: string|null, code: string, message: string}> */
    public array $errors = [];

    /** @var array<int, array{column: string|null, code: string, message: string}> */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $parsed
     */
    public function __construct(public array $parsed = []) {}

    public function error(?string $column, string $code, string $message): self
    {
        $this->errors[] = ['column' => $column, 'code' => $code, 'message' => $message];

        return $this;
    }

    public function warning(?string $column, string $code, string $message): self
    {
        $this->warnings[] = ['column' => $column, 'code' => $code, 'message' => $message];

        return $this;
    }

    public function ok(): bool
    {
        return $this->errors === [];
    }
}
