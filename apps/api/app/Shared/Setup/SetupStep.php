<?php

namespace App\Shared\Setup;

/**
 * IMP-3 · Paso del asistente de primeros pasos. El paso se da por hecho cuando el dato existe en la
 * organización (no se marca a mano); el administrador puede saltarlo y retomarlo.
 */
interface SetupStep
{
    public function code(): string;

    /** Orden en la lista. */
    public function order(): int;

    /** Módulo comercial del paso: sin el módulo contratado, el paso no aparece. */
    public function moduleCode(): string;

    /** Pantalla real a la que lleva el paso (no una copia). */
    public function link(): string;

    /** Ya está hecho en la organización activa (lo decide el dato, no el usuario). */
    public function isDone(): bool;
}
