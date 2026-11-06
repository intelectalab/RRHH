<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\Yaml\Parser;

/**
 * OpenApiAssert — Ayudante de pruebas reutilizable para validar respuestas HTTP
 * contra el contrato publicado en api/openapi.yaml.
 *
 * Uso:
 *   use Tests\Support\OpenApiAssert;
 *   ...
 *   $this->assertMatchesOpenApi($response, 'get', '/platform/health', 200);
 *
 * CLAUDE.md §"Forma de trabajar": toda prueba Feature de un endpoint DEBE
 * llamar a assertMatchesOpenApi() para verificar código de estado y estructura
 * del cuerpo contra la operación exacta del contrato OpenAPI.
 */
trait OpenApiAssert
{
    /**
     * Especificación OpenAPI ya parseada (caché entre tests del mismo proceso).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $openApiSpec = null;

    /**
     * Valida que la respuesta HTTP cumpla la operación declarada en openapi.yaml:
     *  - El código de estado está declarado para ese método+ruta.
     *  - Los campos requeridos por el esquema del cuerpo están presentes.
     *
     * @param  string  $method  Método HTTP en minúsculas: 'get', 'post', 'patch', 'delete'…
     * @param  string  $path  Ruta de la API sin el prefijo /api/v1 ni el tenant, p. ej. '/platform/health'
     * @param  int  $status  Código HTTP esperado (200, 201, 204, 400, 401, 403, 404, 409, 422, 429…)
     */
    protected function assertMatchesOpenApi(TestResponse $response, string $method, string $path, int $status): void
    {
        $spec = $this->loadOpenApiSpec();

        // 1. Verificar que el código de estado está en el contrato
        $operationResponses = $this->findOperationResponses($spec, $method, $path);

        $this->assertNotNull(
            $operationResponses,
            "La operación {$method} {$path} no existe en api/openapi.yaml. ".
            'Ejecuta make openapi si añadiste una ruta nueva.',
        );

        $statusKey = (string) $status;
        $this->assertArrayHasKey(
            $statusKey,
            $operationResponses,
            "El código {$status} no está declarado en openapi.yaml para {$method} {$path}. ".
            'Códigos declarados: '.implode(', ', array_keys($operationResponses)),
        );

        // 2. Verificar que el código de estado real coincide
        $response->assertStatus($status);

        // 3. Si hay esquema definido para ese código, validar campos requeridos
        $responseSpec = $operationResponses[$statusKey];
        $schema = $this->resolveSchema($spec, $responseSpec);

        if ($schema !== null && isset($schema['required']) && $status !== 204) {
            $json = $response->json();
            if (is_array($json)) {
                $this->assertRequiredFields($schema['required'], $json, "{$method} {$path} {$status}");
            }
        }
    }

    /**
     * Carga y almacena en caché la especificación OpenAPI.
     *
     * @return array<string, mixed>
     */
    public function loadOpenApiSpec(): array
    {
        if (self::$openApiSpec !== null) {
            return self::$openApiSpec;
        }

        $candidates = [
            '/var/www/api/openapi.yaml',
            base_path('../../api/openapi.yaml'),
            base_path('../api/openapi.yaml'),
            base_path('api/openapi.yaml'),
            dirname(base_path()).'/api/openapi.yaml',
            dirname(dirname(base_path())).'/api/openapi.yaml',
        ];

        $found = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                $found = $candidate;
                break;
            }
        }

        $this->assertNotNull($found, 'No se pudo localizar api/openapi.yaml para validar el contrato.');

        $parser = new Parser(500, 50_000);
        /** @var array<string, mixed> $parsed */
        $parsed = $parser->parse((string) file_get_contents($found));

        self::$openApiSpec = $parsed;

        return $parsed;
    }

    /**
     * Busca el mapa de respuestas de una operación dada.
     *
     * Busca en paths del spec tanto la ruta exacta como variantes de prefijo
     * (/api/v1/health → /health, etc.).
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>|null
     */
    private function findOperationResponses(array $spec, string $method, string $path): ?array
    {
        $paths = $spec['paths'] ?? [];

        // Intentar primero la ruta exacta, luego sin prefijo /api/v1
        $candidates = [$path];
        foreach (['/api/v1', '/api'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $candidates[] = substr($path, strlen($prefix));
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($paths[$candidate][$method]['responses'])) {
                /** @var array<string, mixed> $responses */
                $responses = $paths[$candidate][$method]['responses'];

                return $this->resolveYamlAnchors($responses);
            }
        }

        return null;
    }

    /**
     * Resuelve $ref y aliases YAML superficiales en un mapa de respuestas.
     * Para el spec de Ayni HR (con aliases *id001, *id002…) extrae el schema real.
     *
     * @param  array<string, mixed>  $responses
     * @return array<string, mixed>
     */
    private function resolveYamlAnchors(array $responses): array
    {
        $resolved = [];
        foreach ($responses as $code => $entry) {
            if (is_array($entry)) {
                $resolved[(string) $code] = $entry;
            } else {
                // Alias YAML ya resuelto por el parser como referencia al anchor
                $resolved[(string) $code] = [];
            }
        }

        return $resolved;
    }

    /**
     * Extrae el schema de respuesta resolviendo $ref básicos.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $responseSpec
     * @return array<string, mixed>|null
     */
    private function resolveSchema(array $spec, array $responseSpec): ?array
    {
        $schema = $responseSpec['content']['application/json']['schema']
            ?? $responseSpec['content']['application/problem+json']['schema']
            ?? null;

        if ($schema === null) {
            return null;
        }

        // Resolver $ref
        if (isset($schema['$ref'])) {
            $ref = (string) $schema['$ref'];
            if (str_starts_with($ref, '#/components/schemas/')) {
                $name = substr($ref, strlen('#/components/schemas/'));
                /** @var array<string, mixed>|null $resolved */
                $resolved = $spec['components']['schemas'][$name] ?? null;

                return $resolved;
            }
        }

        return $schema;
    }

    /**
     * Verifica que todos los campos requeridos estén presentes en el body.
     *
     * @param  string[]  $required
     * @param  array<string, mixed>  $body
     */
    private function assertRequiredFields(array $required, array $body, string $context): void
    {
        foreach ($required as $field) {
            $this->assertArrayHasKey(
                $field,
                $body,
                "Campo requerido '{$field}' ausente en la respuesta ({$context}). ".
                'La respuesta no cumple el esquema publicado en openapi.yaml.',
            );
        }
    }
}
