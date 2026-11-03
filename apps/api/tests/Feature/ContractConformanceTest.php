<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\ModuleNotEnabledException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * PLT-5 · Contratos OpenAPI y tipos compartidos.
 *
 * Criterios evaluados:
 *  1. Las pruebas validan que cada respuesta real cumple el esquema publicado en openapi.yaml.
 *  2. La especificación OpenAPI contiene las definiciones canónicas de Error y esquemas de datos.
 *  3. La documentación navegable se publica en los entornos de prueba (/docs y /docs/openapi.yaml).
 */
class ContractConformanceTest extends TestCase
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $spec = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$spec === null) {
            $paths = [
                '/var/www/api/openapi.yaml',
                base_path('../../api/openapi.yaml'),
                base_path('api/openapi.yaml'),
            ];

            $foundPath = null;
            foreach ($paths as $p) {
                if (file_exists($p)) {
                    $foundPath = $p;
                    break;
                }
            }

            $this->assertNotNull($foundPath, 'No se pudo localizar api/openapi.yaml');
            $parser = new Parser(500, 50000);
            /** @var array<string, mixed> $parsed */
            $parsed = $parser->parse((string) file_get_contents($foundPath));
            self::$spec = $parsed;
        }
    }

    public function test_openapi_spec_has_canonical_components_and_error_schema(): void
    {
        $this->assertIsArray(self::$spec);
        $this->assertArrayHasKey('openapi', self::$spec);
        $this->assertStringStartsWith('3.0', (string) self::$spec['openapi']);
        $this->assertArrayHasKey('paths', self::$spec);
        $this->assertArrayHasKey('components', self::$spec);

        $schemas = self::$spec['components']['schemas'] ?? [];
        $this->assertArrayHasKey('Error', $schemas, 'El esquema canónico Error debe estar en components.schemas');
        $this->assertArrayHasKey('GenericEnvelope', $schemas, 'El esquema GenericEnvelope debe estar en components.schemas');

        // Validar definición del esquema Error
        $errorSchema = $schemas['Error'];
        $this->assertSame('object', $errorSchema['type']);
        $this->assertContains('error', $errorSchema['required']);
        $this->assertSame(['code', 'message', 'request_id'], $errorSchema['properties']['error']['required']);
    }

    public function test_not_found_response_conforms_to_openapi_error_schema(): void
    {
        $response = $this->getJson('/api/v1/ruta-inexistente-conformance');

        $response->assertStatus(404);
        $this->assertResponseConformsToOpenApiErrorSchema($response->json());
    }

    public function test_validation_error_response_conforms_to_openapi_error_schema(): void
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
        $this->assertResponseConformsToOpenApiErrorSchema($response->json());

        // Validar que details contiene field, rule, message conforme al esquema
        $details = $response->json('error.details');
        $this->assertIsArray($details);
        $this->assertNotEmpty($details);
        foreach ($details as $item) {
            $this->assertArrayHasKey('field', $item);
            $this->assertArrayHasKey('message', $item);
        }
    }

    public function test_business_rule_exception_conforms_to_openapi_error_schema(): void
    {
        Route::get('/test-contract-business-rule', function () {
            throw new BusinessRuleViolatedException('payroll_already_closed', 'La planilla ya se encuentra cerrada');
        });

        $response = $this->getJson('/test-contract-business-rule');

        $response->assertStatus(422);
        $this->assertResponseConformsToOpenApiErrorSchema($response->json());
        $this->assertSame('payroll_already_closed', $response->json('error.meta.rule'));
    }

    public function test_module_not_enabled_conforms_to_openapi_error_schema(): void
    {
        Route::get('/test-contract-module', function () {
            throw new ModuleNotEnabledException('recruiting');
        });

        $response = $this->getJson('/test-contract-module');

        $response->assertStatus(403);
        $this->assertResponseConformsToOpenApiErrorSchema($response->json());
    }

    public function test_health_endpoint_response_conforms_to_generic_envelope_schema(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $json = $response->json();

        // Conforme al GenericEnvelope de OpenAPI: objeto con data obligatorio
        $this->assertIsArray($json);
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('status', $json['data']);
        $this->assertSame('ok', $json['data']['status']);
    }

    public function test_navigable_api_docs_are_published_in_test_environment(): void
    {
        // En entorno de prueba (testing/local), /docs entrega Swagger UI
        $docsResponse = $this->get('/docs');
        $docsResponse->assertOk();
        $this->assertStringContainsString('text/html', (string) $docsResponse->headers->get('Content-Type'));
        $this->assertStringContainsString('swagger-ui', $docsResponse->getContent());
        $this->assertStringContainsString('/docs/openapi.yaml', $docsResponse->getContent());

        // /docs/openapi.yaml entrega la especificación OpenAPI en YAML
        $specResponse = $this->get('/docs/openapi.yaml');
        $specResponse->assertOk();
        $this->assertStringContainsString('openapi:', $specResponse->getContent());
    }

    /**
     * Valida que la estructura del array JSON cumple con el esquema canónico Error de openapi.yaml.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertResponseConformsToOpenApiErrorSchema(array $data): void
    {
        $this->assertArrayHasKey('error', $data, 'La respuesta de error debe tener clave raíz "error"');
        $error = $data['error'];
        $this->assertIsArray($error);

        // Campos requeridos por components.schemas.Error
        $this->assertArrayHasKey('code', $error);
        $this->assertIsString($error['code']);
        $this->assertNotEmpty($error['code']);

        $this->assertArrayHasKey('message', $error);
        $this->assertIsString($error['message']);
        $this->assertNotEmpty($error['message']);

        $this->assertArrayHasKey('request_id', $error);
        $this->assertIsString($error['request_id']);
        $this->assertNotEmpty($error['request_id']);

        // details debe ser array si está presente
        if (array_key_exists('details', $error)) {
            $this->assertIsArray($error['details']);
        }
    }
}
