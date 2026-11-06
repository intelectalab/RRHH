<?php

namespace Tests\Feature;

use App\Shared\Http\Middleware\EnsureIdempotency;
use App\Shared\Idempotency\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\TenantTestHelper;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use DatabaseTransactions;
    use TenantTestHelper;

    private string $tenantId;

    private int $executionCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = $this->createAndBindTenant();
        $this->executionCount = 0;

        // Ruta de prueba con el middleware EnsureIdempotency
        Route::middleware(EnsureIdempotency::class)->post('/test-idempotency', function (Request $request) {
            $this->executionCount++;

            return response()->json([
                'success' => true,
                'counter' => $this->executionCount,
                'data' => $request->all(),
            ], 201);
        });
    }

    public function test_repeating_request_with_same_key_returns_same_result_without_duplicating_effect(): void
    {
        $idempotencyKey = 'idemp-'.Str::random(16);
        $payload = ['item' => 'licencia_maternidad', 'dias' => 98];

        // Primera llamada: crea y ejecuta el efecto
        $response1 = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/test-idempotency', $payload);

        $response1->assertStatus(201);
        $response1->assertJsonPath('counter', 1);
        $this->assertSame(1, $this->executionCount);

        // Segunda llamada con la misma clave y payload: devuelve la respuesta original sin repetir efecto
        $response2 = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/test-idempotency', $payload);

        $response2->assertStatus(201);
        $response2->assertHeader('Idempotent-Replay', 'true');
        $response2->assertJsonPath('counter', 1); // El contador sigue en 1
        $this->assertSame(1, $this->executionCount); // El controlador NO se volvió a ejecutar
    }

    public function test_reusing_key_with_different_payload_returns_409_conflict(): void
    {
        $idempotencyKey = 'idemp-'.Str::random(16);

        // Primera llamada con payload A
        $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/test-idempotency', ['item' => 'A'])->assertStatus(201);

        // Segunda llamada con la misma clave pero payload B -> 409 idempotency_conflict
        $response = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/test-idempotency', ['item' => 'B']);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_concurrent_request_with_active_lock_returns_409_request_in_progress(): void
    {
        $idempotencyKey = 'idemp-'.Str::random(16);

        // Simulamos un registro bloqueado en procesamiento
        IdempotencyKey::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'key' => $idempotencyKey,
            'request_method' => 'POST',
            'request_path' => 'test-idempotency',
            'request_hash' => hash('sha256', json_encode(['foo' => 'bar'])),
            'locked_until' => now()->addMinutes(2),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/test-idempotency', ['foo' => 'bar']);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'request_in_progress');
    }

    public function test_idempotency_applies_to_api_routes_automatically(): void
    {
        Route::middleware('api')->post('/api/v1/test-auto-idempotent', function (Request $request) {
            return response()->json(['processed' => true, 'data' => $request->all()], 200);
        });

        $idempotencyKey = 'idemp-auto-'.Str::random(16);
        $payload = ['action' => 'process_payment'];

        $res1 = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/test-auto-idempotent', $payload);

        $res1->assertStatus(200);

        $res2 = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/test-auto-idempotent', $payload);

        $res2->assertStatus(200);
        $res2->assertHeader('Idempotent-Replay', 'true');
    }
}
