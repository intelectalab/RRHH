<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use League\OpenAPIValidation\PSR7\Exception\ValidationFailed;
use League\OpenAPIValidation\PSR7\OperationAddress;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Yaml\Parser;

/**
 * OpenApiAssert — Ayudante de pruebas reutilizable para validar respuestas HTTP
 * contra el contrato publicado en api/openapi.yaml usando league/openapi-psr7-validator.
 *
 * Valida de forma estricta:
 *  - Código de estado declarado en la operación
 *  - Content-Type de respuesta
 *  - Tipos primitivos y estructuras
 *  - Enums y formatos
 *  - Campos obligatorios
 */
trait OpenApiAssert
{
    /**
     * Especificación OpenAPI parseada en array (caché en memoria).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $openApiSpec = null;

    /**
     * Validador PSR-7 de OpenAPI cacheado.
     */
    private static ?ResponseValidator $responseValidator = null;

    /**
     * Valida que la respuesta HTTP cumpla la operación declarada en openapi.yaml
     * usando el validador oficial league/openapi-psr7-validator.
     *
     * @param  string  $method  Método HTTP en minúsculas: 'get', 'post', 'patch', 'delete'…
     * @param  string  $path  Ruta de la API según openapi.yaml, p. ej. '/platform/health'
     * @param  int  $status  Código HTTP esperado
     */
    protected function assertMatchesOpenApi(TestResponse $response, string $method, string $path, int $status): void
    {
        $response->assertStatus($status);

        $validator = $this->getOpenApiResponseValidator();

        // Normalizar ruta a la forma declarada en openapi.yaml
        $normalizedPath = $this->normalizeOpenApiPath($path);

        $operation = new OperationAddress($normalizedPath, strtolower($method));

        // Convertir respuesta de Laravel a PSR-7
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[$name] = $values;
        }

        $psr7Response = new Psr7Response(
            $response->status(),
            $headers,
            (string) $response->getContent(),
        );

        try {
            $validator->validate($operation, $psr7Response);
        } catch (ValidationFailed $e) {
            $previous = $e->getPrevious() ? ' (Causa: '.$e->getPrevious()->getMessage().')' : '';
            throw new AssertionFailedError(
                "La respuesta no coincide con el contrato OpenAPI para {$method} {$normalizedPath} [{$status}]: ".
                $e->getMessage().$previous
            );
        } catch (\Throwable $e) {
            throw new AssertionFailedError(
                "Error validando contrato OpenAPI para {$method} {$normalizedPath} [{$status}]: ".$e->getMessage()
            );
        }
    }

    /**
     * Obtiene o construye la instancia singleton de ResponseValidator.
     */
    protected function getOpenApiResponseValidator(): ResponseValidator
    {
        if (self::$responseValidator !== null) {
            return self::$responseValidator;
        }

        $spec = $this->loadOpenApiSpec();
        $json = (string) json_encode($spec);

        $builder = (new ValidatorBuilder)->fromJson($json);
        self::$responseValidator = $builder->getResponseValidator();

        return self::$responseValidator;
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
            '/var/www/kit/api/openapi.yaml',
            base_path('../../kit/api/openapi.yaml'),
            base_path('../kit/api/openapi.yaml'),
            base_path('kit/api/openapi.yaml'),
            dirname(base_path()).'/kit/api/openapi.yaml',
            dirname(dirname(base_path())).'/kit/api/openapi.yaml',
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
     * Normaliza la ruta eliminando prefijos de versión o buscando la ruta coincidente en el spec.
     */
    private function normalizeOpenApiPath(string $path): string
    {
        $spec = $this->loadOpenApiSpec();
        $paths = $spec['paths'] ?? [];

        if (isset($paths[$path])) {
            return $path;
        }

        foreach (['/api/v1', '/api'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $stripped = substr($path, strlen($prefix));
                if (isset($paths[$stripped])) {
                    return $stripped;
                }
            }
        }

        return $path;
    }
}
