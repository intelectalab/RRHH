<?php

/**
 * Tests de arquitectura que verifican los límites del monolito modular.
 *
 * Reglas (CLAUDE.md):
 * - El dominio de cada módulo no importa clases de Eloquent ni de HTTP.
 * - withoutGlobalScopes() solo puede usarse en app/Shared/Tenancy.
 * - Ningún módulo importa clases de las capas internas (Domain / Application /
 *   Infrastructure) de otro módulo. Los módulos solo se comunican a través de
 *   contratos públicos (puertos, eventos, DTOs de app/Shared).
 */

/*
 * Regla 1: el dominio de cada módulo no usa Eloquent ni HTTP.
 * Se verifica módulo a módulo para que el error identifique el módulo infractor.
 */
arch('Domain de Platform no usa Eloquent ni HTTP')
    ->expect('App\Modules\Platform\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Http',
    ]);

arch('Domain de CoreHR no usa Eloquent ni HTTP')
    ->expect('App\Modules\CoreHR\Domain')
    ->not->toUse([
        'Illuminate\Database\Eloquent',
        'Illuminate\Http',
    ]);

/*
 * Regla 2: withoutGlobalScopes() solo puede llamarse desde Shared\Tenancy.
 */
arch('withoutGlobalScopes es exclusivo de Shared\\Tenancy')
    ->expect('App')
    ->not->toUse('withoutGlobalScopes')
    ->ignoring('App\Shared\Tenancy');

/*
 * Regla 3: ningún módulo importa las capas internas de otro módulo.
 *
 * Mecanismo: para cada módulo X se verifica que su namespace NO usa clases
 * del namespace interno de cualquier otro módulo Y (Domain, Application,
 * Infrastructure).  Cada módulo declara su «zona prohibida» de los demás.
 *
 * Al añadir un módulo nuevo:
 *   1. Añadir su espacio en la lista de zonas prohibidas de los demás.
 *   2. Añadir un bloque análogo que prohíba que él importe a los demás.
 */
$modulosInternos = [
    'App\Modules\Platform\Domain',
    'App\Modules\Platform\Application',
    'App\Modules\Platform\Infrastructure',
    'App\Modules\CoreHR\Domain',
    'App\Modules\CoreHR\Application',
    'App\Modules\CoreHR\Infrastructure',
];

arch('Platform no importa capas internas de otros módulos')
    ->expect('App\Modules\Platform')
    ->not->toUse([
        'App\Modules\CoreHR\Domain',
        'App\Modules\CoreHR\Application',
        'App\Modules\CoreHR\Infrastructure',
    ]);

arch('CoreHR no importa capas internas de otros módulos')
    ->expect('App\Modules\CoreHR')
    ->not->toUse([
        'App\Modules\Platform\Domain',
        'App\Modules\Platform\Application',
        'App\Modules\Platform\Infrastructure',
    ]);

/*
 * Regla 4: ningún módulo importa implementaciones falsas o concretas de adaptadores.
 * Deben depender de los puertos (App\Shared\Integrations\Ports) o de IntegrationManager.
 */
arch('módulos no importan implementaciones falsas de integración directamente')
    ->expect('App\Modules')
    ->not->toUse([
        'App\Shared\Integrations\Fake',
    ]);
