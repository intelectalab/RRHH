<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Shared\Exceptions\BusinessRuleViolatedException;
use App\Shared\Exceptions\ModuleNotEnabledException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * PLT-4 · Formato único de errores y validación en servidor.
 *
 * Verifica que todas las respuestas de error de la API:
 *  - Usan Content-Type: application/json
 *  - Incluyen el header X-Request-Id
 *  - Devuelven la estructura { error: { code, message, request_id, details, meta } }
 */
class ErrorFormatTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // 404 · Ruta no encontrada
    // ─────────────────────────────────────────────────────────────────────────

    public function test_unknown_route_returns_404_not_found(): void
    {
        $response = $this->getJson('/api/v1/ruta-que-no-existe');

        $response->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('error.code', 'not_found')
            ->assertJsonStructure([
                'error' => ['code', 'message', 'request_id', 'details', 'meta'],
            ]);

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 422 · ValidationException con details por campo
    // ─────────────────────────────────────────────────────────────────────────

    public function test_validation_exception_returns_422_with_field_details(): void
    {
        // Registramos una ruta de prueba que lanza ValidationException
        Route::post('/test-validation', function () {
            request()->validate([
                'email' => ['required', 'email'],
                'name' => ['required', 'min:3'],
            ]);
        });

        $response = $this->postJson('/test-validation', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure([
                'error' => ['code', 'message', 'request_id', 'details', 'meta'],
            ]);

        $details = $response->json('error.details');
        $this->assertIsArray($details);
        $this->assertNotEmpty($details);

        // Comprobamos que los detalles incluyen al menos los campos inválidos
        $fields = array_column($details, 'field');
        $this->assertContains('email', $fields);
        $this->assertContains('name', $fields);

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 409 · AyniException: BusinessRuleViolatedException
    // ─────────────────────────────────────────────────────────────────────────

    public function test_business_rule_violated_exception_renders_correctly(): void
    {
        Route::get('/test-business-rule', function () {
            throw new BusinessRuleViolatedException('offboarding_conflict');
        });

        $response = $this->getJson('/test-business-rule');

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('error.code', 'business_rule_violated')
            ->assertJsonStructure([
                'error' => ['code', 'message', 'request_id', 'details', 'meta'],
            ]);

        // Meta debe incluir la regla que fue violada
        $this->assertSame('offboarding_conflict', $response->json('error.meta.rule'));
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 403 · AyniException: ModuleNotEnabledException
    // ─────────────────────────────────────────────────────────────────────────

    public function test_module_not_enabled_exception_renders_correctly(): void
    {
        Route::get('/test-module-not-enabled', function () {
            throw new ModuleNotEnabledException('performance');
        });

        $response = $this->getJson('/test-module-not-enabled');

        $response->assertStatus(403)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('error.code', 'module_not_enabled')
            ->assertJsonStructure([
                'error' => ['code', 'message', 'request_id', 'details', 'meta'],
            ]);

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // X-Request-Id: el cliente puede enviar su propio ID y se devuelve igual
    // ─────────────────────────────────────────────────────────────────────────

    public function test_client_request_id_is_echoed_in_response(): void
    {
        $clientId = 'test-request-id-abc123';

        $response = $this->getJson('/api/v1/ruta-que-no-existe', [
            'X-Request-Id' => $clientId,
        ]);

        $response->assertStatus(404);
        $this->assertSame($clientId, $response->headers->get('X-Request-Id'));
        $this->assertSame($clientId, $response->json('error.request_id'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // X-Request-Id: se genera automáticamente si el cliente no lo envía
    // ─────────────────────────────────────────────────────────────────────────

    public function test_request_id_is_auto_generated_when_not_provided(): void
    {
        $response = $this->getJson('/api/v1/ruta-que-no-existe');

        $response->assertStatus(404);

        $requestId = $response->headers->get('X-Request-Id');
        $this->assertNotEmpty($requestId);
        // Debe ser un UUID v4
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $requestId,
        );
    }
}
