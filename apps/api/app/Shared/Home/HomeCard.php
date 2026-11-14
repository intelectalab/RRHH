<?php

namespace App\Shared\Home;

/**
 * COL-12 · MSS-1 · Tarjeta de un tablero de inicio. Cada módulo registra las suyas en HomeRegistry; el
 * tablero solo muestra las de módulos contratados y con el permiso del recurso de origen. Los números se
 * calculan en el servidor con el alcance del usuario aplicado en la consulta (HomeContext::restrict).
 */
interface HomeCard
{
    public const HR = 'hr';

    public const MANAGER = 'manager';

    /** Clave estable de la tarjeta (headcount, absent_today…); la interfaz la traduce. */
    public function key(): string;

    /** Permiso del recurso de origen; su módulo debe estar contratado. */
    public function permission(): string;

    /**
     * Tableros donde aparece (hr, manager).
     *
     * @return list<string>
     */
    public function audiences(): array;

    /** Orden dentro del tablero (menor primero). */
    public function order(): int;

    /**
     * Valor de la tarjeta, o null si no aplica (p. ej. el usuario no tiene datos que mostrar).
     *
     * - value: número principal (entero o decimal como texto) o null cuando la tarjeta es solo una lista.
     * - link: ruta de la interfaz al listado filtrado que explica el número.
     * - tone: info | success | warning | danger | null.
     * - items: hasta 8 elementos para mostrar dentro de la tarjeta (persona, fecha, enlace).
     * - meta: datos adicionales (moneda, umbral, explicación).
     *
     * @return array{value: int|string|null, link: string, tone?: string|null, items?: list<array<string, mixed>>, meta?: array<string, mixed>}|null
     */
    public function compute(HomeContext $context): ?array;
}
