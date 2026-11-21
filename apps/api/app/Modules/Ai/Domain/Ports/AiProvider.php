<?php

namespace App\Modules\Ai\Domain\Ports;

use App\Modules\Ai\Domain\AiCompletion;
use App\Modules\Ai\Domain\AiPrompt;

/**
 * IAR-1 · Puerto del proveedor de IA. El dominio y los casos de uso solo conocen esta interfaz: ningún SDK ni
 * cliente HTTP de un proveedor entra en Domain/ ni en Application/ (regla 8). El proveedor recibe únicamente las
 * fuentes que el caso de uso ya filtró con los permisos del usuario y debe citar solo esas fuentes; el caso de
 * uso descarta cualquier cita fuera de la lista (regla 13: la IA asiste, no decide, y no ve más que el usuario).
 *
 * Decisión A-09 (qué proveedor real se usa) abierta: por ahora solo existe el adaptador falso determinista.
 */
interface AiProvider
{
    public function answer(AiPrompt $prompt): AiCompletion;

    public function name(): string;

    public function isFake(): bool;
}
