<?php

/**
 * SEG-8 · Todo endpoint declara el permiso que exige y coincide con openapi.yaml (x-permission).
 *  - public         → sin middleware de autenticación
 *  - authenticated  → auth:ayni
 *  - <código>       → auth:ayni + permission:<código>, y el código existe en el catálogo
 * Consola SaaS (/platform/…, SAS-1), con guard propio y sin auth:ayni:
 *  - public             → sin sesión
 *  - platform           → auth:platform
 *  - platform.<código>  → auth:platform + platform.mfa + platform.permission:<código>
 */

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

test('cada ruta de la API declara exactamente el permiso del contrato', function (): void {
    $spec = test()->loadOpenApiSpec();
    $catalog = DB::table('permissions')->pluck('code')->all();
    $problems = [];

    /** @var RoutingRoute $route */
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');
        if (! str_starts_with($uri, '/api/v1/') || str_contains($uri, '/__')) {
            continue;
        }
        $path = substr($uri, strlen('/api/v1'));
        $middleware = $route->gatherMiddleware();
        $declared = null;
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'permission:')) {
                $declared = substr($m, strlen('permission:'));
            }
        }
        $authenticated = in_array('auth:ayni', $middleware, true);
        $console = in_array('auth:platform', $middleware, true);
        $consolePermission = null;
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'platform.permission:')) {
                $consolePermission = substr($m, strlen('platform.permission:'));
            }
        }

        foreach ($route->methods() as $method) {
            $method = strtolower($method);
            if (in_array($method, ['head', 'options'], true)) {
                continue;
            }
            $expected = $spec['paths'][$path][$method]['x-permission'] ?? null;
            $label = strtoupper($method).' '.$uri;

            if (str_starts_with($path, '/platform/')) {
                if (! in_array('platform.console', $middleware, true)) {
                    $problems[] = "{$label}: la consola debe pasar por platform.console";
                }
                if ($expected === null) {
                    $problems[] = "{$label}: sin x-permission en el contrato";
                } elseif ($authenticated || $declared !== null) {
                    $problems[] = "{$label}: la consola no usa la sesión de las organizaciones";
                } elseif ($expected === 'public') {
                    if ($console || $consolePermission !== null) {
                        $problems[] = "{$label}: el contrato dice público pero la ruta exige sesión";
                    }
                } elseif ($expected === 'platform') {
                    if (! $console || $consolePermission !== null) {
                        $problems[] = "{$label}: debe exigir solo la sesión de la consola (auth:platform)";
                    }
                } else {
                    if (! $console || ! in_array('platform.mfa', $middleware, true) || $consolePermission !== $expected) {
                        $problems[] = "{$label}: debe declarar auth:platform, platform.mfa y platform.permission:{$expected}";
                    }
                    if (! in_array($expected, $catalog, true)) {
                        $problems[] = "{$label}: el permiso {$expected} no existe en el catálogo";
                    }
                }

                continue;
            }

            if ($expected === null) {
                $problems[] = "{$label}: sin x-permission en el contrato";
            } elseif ($expected === 'public') {
                if ($authenticated || $declared !== null) {
                    $problems[] = "{$label}: el contrato dice público pero la ruta exige sesión";
                }
            } elseif ($expected === 'authenticated') {
                if (! $authenticated || $declared !== null) {
                    $problems[] = "{$label}: debe exigir solo sesión (auth:ayni)";
                }
            } else {
                if (! $authenticated || $declared !== $expected) {
                    $problems[] = "{$label}: debe declarar auth:ayni y permission:{$expected} (tiene ".($declared ?? 'ninguno').')';
                }
                if (! in_array($expected, $catalog, true)) {
                    $problems[] = "{$label}: el permiso {$expected} no existe en el catálogo";
                }
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});
