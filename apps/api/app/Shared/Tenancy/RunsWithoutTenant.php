<?php

namespace App\Shared\Tenancy;

/**
 * Marca un trabajo en cola que opera sin organización (consola de plataforma o
 * despachador que itera organizaciones y encola un trabajo por cada una).
 *
 * Todo trabajo que no implemente esta interfaz se encola con la organización
 * activa; sin organización activa, no se encola (TEN-6).
 */
interface RunsWithoutTenant {}
