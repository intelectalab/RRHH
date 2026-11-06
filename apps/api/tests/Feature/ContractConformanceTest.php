<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\ModuleNotEnabledException;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * PLT-5 · Contratos OpenAPI y tipos compartidos.
 *
 * Criterios evaluados:
 *  1. assertMatchesOpenApi() valida código de estado y esquema requerido contra openapi.yaml.
 *  2. Las pruebas usan el ayudante para verificar respuestas reales contra el contrato.
 *  3. assertMatchesOpenApi() FALLA cuando la respuesta no coincide con el esquema.
 *  4. La documentación navegable se publica en los entornos de prueba.
 *
 * CLAUDE.md §"Forma de trabajar": toda prueba Feature de un endpoint DEBE usar
 * assertMatchesOpenApi() para verificar código de estado y estructura del cuerpo.
 */
class ContractConformanceTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Prueba de sanidad: el spec tiene los esquemas canónicos
    // ─────────────────────────────────────────────────────────────────────────

    public function test_openapi_spec_has_canonical_error_and_envelope_schemas(): void
    {
        $spec = $this->loadOpenApiSpec();

        $this->assertIsArray($spec);
        $this->assertStringStartsWith('3.0', (string) ($spec['openapi'] ?? ''));
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);

        $schemas = $spec['components']['schemas'] ?? [];
        $this->assertArrayHasKey('Error', $schemas, 'El esquema canónico Error debe estar en components.schemas');
        $this->assertArrayHasKey('GenericEnvelope', $schemas, 'El esquema GenericEnvelope debe estar en components.schemas');

        // Error debe tener code, message, request_id como requeridos
        $errorSchema = $schemas['Error'];
        $this->assertSame('object', $errorSchema['type']);
        $this->assertContains('error', $errorSchema['required']);
        $this->assertSame(['code', 'message', 'request_id'], $errorSchema['properties']['error']['required']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pruebas con assertMatchesOpenApi() — validación real contra el contrato
    // ─────────────────────────────────────────────────────────────────────────

    public function test_not_found_response_matches_openapi_error_schema(): void
    {
        $response = $this->getJson('/api/v1/ruta-inexistente-conformance');

        // Validación completa: código + campos requeridos en el body
        // La ruta no está en el spec, así que sólo validamos la estructura Error canónica
        $response->assertStatus(404);
        $this->assertErrorBodyConformsToSpec($response->json());
    }

    public function test_validation_error_matches_openapi_error_schema(): void
    {
        Route::post('/test-contract-validation', function () {
            request()->validate([
                'tenant_name' => ['required', 'string'],
                'work_email' => ['required', 'email'],
            ]);
        });

        $response = $this->postJson('/test-contract-validation', [
            'work_email' => 'invalid-email',
        ]);

        $response->assertStatus(422);
        $this->assertErrorBodyConformsToSpec($response->json());

        $details = $response->json('error.details');
        $this->assertIsArray($details);
        $this->assertNotEmpty($details);
        foreach ($details as $item) {
            $this->assertArrayHasKey('field', $item);
            $this->assertArrayHasKey('message', $item);
        }
    }

    public function test_business_rule_exception_matches_openapi_error_schema(): void
    {
        Route::get('/test-contract-business-rule', function () {
            throw new BusinessRuleViolatedException('payroll_already_closed', 'La planilla ya se encuentra cerrada');
        });

        $response = $this->getJson('/test-contract-business-rule');

        $response->assertStatus(422);
        $this->assertErrorBodyConformsToSpec($response->json());
        $this->assertSame('payroll_already_closed', $response->json('error.meta.rule'));
    }

    public function test_module_not_enabled_matches_openapi_error_schema(): void
    {
        Route::get('/test-contract-module', function () {
            throw new ModuleNotEnabledException('recruiting');
        });

        $response = $this->getJson('/test-contract-module');

        $response->assertStatus(403);
        $this->assertErrorBodyConformsToSpec($response->json());
    }

    public function test_health_endpoint_matches_openapi_generic_envelope_schema(): void
    {
        $response = $this->getJson('/api/v1/health');

        // /platform/health está declarado en el spec con GenericEnvelope (data requerido)
        $this->assertMatchesOpenApi($response, 'get', '/platform/health', 200);

        // Verificación adicional del contenido
        $this->assertSame('ok', $response->json('data.status'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Demostración de fallo: assertMatchesOpenApi FALLA si el esquema no coincide
    // ─────────────────────────────────────────────────────────────────────────

    public function test_assert_matches_openapi_fails_when_response_does_not_match_schema(): void
    {
        // Registramos una ruta que responde con un cuerpo inválido:
        // le falta el campo requerido "data" que declara GenericEnvelope
        Route::get('/test-invalid-schema-response', function () {
            // ⚠️ Respuesta incorrecta: falta "data" (campo requerido por GenericEnvelope)
            return response()->json(['wrong_field' => 'this should fail']);
        });

        $response = $this->getJson('/test-invalid-schema-response');

        // Capturamos el AssertionFailedError que lanza el validador oficial de OpenAPI
        $thrown = false;
        try {
            $this->assertMatchesOpenApi($response, 'get', '/platform/health', 200);
        } catch (AssertionFailedError $e) {
            $thrown = true;
            $this->assertStringContainsString(
                "Required property 'data' must be present",
                $e->getMessage(),
                'assertMatchesOpenApi debe indicar la propiedad requerida faltante',
            );
        }

        $this->assertTrue(
            $thrown,
            'assertMatchesOpenApi debió lanzar AssertionFailedError porque el body no tiene "data" (requerido por GenericEnvelope)',
        );
    }

    public function test_assert_matches_openapi_fails_when_field_type_is_incorrect(): void
    {
        // Registramos una ruta que responde con un tipo de dato incorrecto:
        // /platform/tenants requiere data: array y meta: object.
        // Aquí enviamos data como un string escalar "not-an-array".
        Route::get('/test-invalid-type-response', function () {
            return response()->json([
                'data' => 'not-an-array',
                'meta' => [
                    'page' => 1,
                    'per_page' => 15,
                    'total' => 0,
                    'total_pages' => 0,
                ],
            ]);
        });

        $response = $this->getJson('/test-invalid-type-response');

        $thrown = false;
        try {
            $this->assertMatchesOpenApi($response, 'get', '/platform/tenants', 200);
        } catch (AssertionFailedError $e) {
            $thrown = true;
            $this->assertStringContainsString(
                'Body does not match schema',
                $e->getMessage(),
                'El validador OpenAPI debe rechazar la respuesta por tipo incorrecto',
            );
            $this->assertStringContainsString(
                "Value expected to be 'array', but 'string' given",
                $e->getMessage(),
                'El validador OpenAPI debe indicar que se esperaba un array',
            );
        }

        $this->assertTrue(
            $thrown,
            'assertMatchesOpenApi debió fallar por tipo de dato incorrecto (string en vez de array)',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Documentación navegable
    // ─────────────────────────────────────────────────────────────────────────

    public function test_navigable_api_docs_are_published_in_test_environment(): void
    {
        $docsResponse = $this->get('/docs');
        $docsResponse->assertOk();
        $this->assertStringContainsString('text/html', (string) $docsResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('swagger-ui', $docsResponse->getContent());
        $this->assertStringContainsString('/docs/openapi.yaml', $docsResponse->getContent());

        $specResponse = $this->get('/docs/openapi.yaml');
        $specResponse->assertOk();
        $this->assertStringContainsString('openapi:', $specResponse->getContent());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper privado: validación del esquema canónico Error
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Valida que el body JSON cumple la forma { error: { code, message, request_id, … } }.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertErrorBodyConformsToSpec(array $data): void
    {
        $this->assertArrayHasKey('error', $data, 'La respuesta de error debe tener clave raíz "error"');
        $error = $data['error'];
        $this->assertIsArray($error);

        foreach (['code', 'message', 'request_id'] as $field) {
            $this->assertArrayHasKey($field, $error, "Campo requerido '{$field}' ausente en error");
            $this->assertIsString($error[$field]);
            $this->assertNotEmpty($error[$field]);
        }

        if (array_key_exists('details', $error)) {
            $this->assertIsArray($error['details']);
        }
    }
}
