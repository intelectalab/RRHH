<?php

namespace App\Shared\Setup;

/**
 * IMP-4 · Parte de un paquete de país que siembra un módulo (tipos de contrato, de ausencia, feriados,
 * plantillas, políticas, motivos de cese, roles). Cada módulo dueño registra la suya en CountryPackRegistry;
 * el paquete (packages/country-xx/data/paquete.php) declara qué partes incluye.
 *
 * Aplicar es idempotente: solo crea lo que falta y nunca pisa lo que la organización ya editó. Lo creado
 * queda editable en la pantalla real del módulo.
 */
interface CountryPackComponent
{
    /** Código de la parte en el manifiesto del paquete (p. ej. contract_types). */
    public function code(): string;

    /** Módulo comercial que la usa: si no está contratado, la parte no se aplica. */
    public function moduleCode(): string;

    /**
     * Nombres de lo que la parte crea en una organización del país (lo que se muestra antes de aplicar).
     *
     * @return list<string>
     */
    public function items(string $country): array;

    /** Cuántos registros de la parte ya existen en la organización activa. */
    public function present(string $country): int;

    /** Crea lo que falte en la organización activa. */
    public function apply(string $country, ?string $userId): void;

    /** Pantalla real donde se edita lo creado. */
    public function link(): string;
}
