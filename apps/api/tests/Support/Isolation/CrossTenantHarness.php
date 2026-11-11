<?php

namespace Tests\Support\Isolation;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Notifications\Infrastructure\Models\Notification;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use App\Modules\Settings\Infrastructure\Models\CustomFieldDefinition;
use App\Modules\Settings\Infrastructure\Models\TenantSetting;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\IdentityFixtures;
use Tests\TestCase;

/**
 * TEN-4 · Prueba automática de aislamiento sobre todos los endpoints.
 *
 * Recorre las operaciones de api/openapi.yaml que tienen ruta registrada en
 * Laravel y, con credenciales de la organización B, intenta leer, modificar,
 * listar y buscar recursos creados en la organización A.
 *
 * Un endpoint nuevo queda cubierto sin escribir una prueba: basta con que su
 * modelo Eloquent use HasFactory y se ubique por convención en
 * App\Modules\{Modulo}\Infrastructure\Models\{Esquema}. Si el harness no puede
 * sembrar el recurso, el endpoint se informa como «no cubierto» y la prueba falla.
 */
final class CrossTenantHarness
{
    /** @var array<string, class-string<Model>> Excepciones a la convención: esquema OpenAPI => modelo */
    public const MODEL_OVERRIDES = [
        // La búsqueda global se prueba sembrando usuarios de A: B no debe encontrarlos.
        '/search' => User::class,
        // Listados propios o de registro (sin esquema de recurso): se siembran avisos y entregas de A.
        '/notifications' => Notification::class,
        '/trash/{id}/restore' => User::class,
        '/me/sessions/{id}/revoke' => User::class,
        'CustomField' => CustomFieldDefinition::class,
        '/settings/{group}' => TenantSetting::class,
        '/notification-deliveries' => NotificationDelivery::class,
    ];

    /**
     * Listados sin esquema de recurso propio que igual devuelven datos de la
     * organización: exigen un modelo declarado en MODEL_OVERRIDES con su ruta.
     *
     * @var array<int, string>
     */
    public const AGGREGATING_COLLECTIONS = ['/search', '/notifications', '/notification-deliveries'];

    /** @var array<int, string> Permisos de operaciones que no requieren aislamiento por id */
    private const EXEMPT_PERMISSIONS = ['public', 'platform'];

    /** @var array<string, User> */
    private array $actors = [];

    /** @var array<int, string> */
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @param  array<string, mixed>  $spec  OpenAPI ya parseado
     */
    public function __construct(private readonly array $spec) {}

    /**
     * Operaciones del contrato con ruta registrada en Laravel.
     *
     * @param  iterable<Route>  $routes
     * @return array<int, array{method: string, path: string, operation: array<string, mixed>}>
     */
    public function registeredOperations(iterable $routes): array
    {
        $registered = [];
        foreach ($routes as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (! str_starts_with($uri, '/api/v1/') || str_contains($uri, '/__')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                $registered[strtolower($method).' '.substr($uri, strlen('/api/v1'))] = true;
            }
        }

        $operations = [];
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $this->spec['paths'] ?? [];
        foreach ($paths as $path => $item) {
            foreach (self::HTTP_METHODS as $method) {
                $key = $method.' '.$this->laravelPath($path);
                if (isset($item[$method]) && isset($registered[$key])) {
                    /** @var array<string, mixed> $op */
                    $op = $item[$method];
                    $operations[] = ['method' => $method, 'path' => $path, 'operation' => $op];
                }
            }
        }

        return $operations;
    }

    /**
     * Rutas registradas bajo /api/v1 que no están en el contrato.
     *
     * @param  iterable<Route>  $routes
     * @return array<int, string>
     */
    public function routesMissingFromContract(iterable $routes): array
    {
        $contract = [];
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $this->spec['paths'] ?? [];
        foreach ($paths as $path => $item) {
            foreach (self::HTTP_METHODS as $method) {
                if (isset($item[$method])) {
                    $contract[$method.' '.$this->laravelPath($path)] = true;
                }
            }
        }

        $missing = [];
        foreach ($routes as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (! str_starts_with($uri, '/api/v1/') || str_contains($uri, '/__')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                $method = strtolower($method);
                if ($method === 'head' || $method === 'options') {
                    continue;
                }
                $key = $method.' '.substr($uri, strlen('/api/v1'));
                if (! isset($contract[$key])) {
                    $missing[] = strtoupper($method).' '.$uri;
                }
            }
        }

        return $missing;
    }

    /**
     * Ejecuta la batería cruzada. Devuelve infracciones y endpoints no cubiertos.
     *
     * @param  array<int, array{method: string, path: string, operation: array<string, mixed>}>  $operations
     * @return array{violations: array<int, string>, uncovered: array<int, string>, checked: int}
     */
    public function run(TestCase $test, array $operations, string $tenantA, string $tenantB): array
    {
        $violations = [];
        $uncovered = [];
        $checked = 0;

        foreach ($operations as $op) {
            $label = strtoupper($op['method']).' '.$op['path'];
            $permission = (string) ($op['operation']['x-permission'] ?? '');
            if (in_array($permission, self::EXEMPT_PERMISSIONS, true) && ! str_contains($op['path'], '{')) {
                continue;
            }

            $schema = $this->resourceSchema($op['path'], $op['method']);
            $hasParam = str_contains($op['path'], '{');

            // Acciones sobre la propia sesión o el propio usuario (logout, preferencias):
            // no hay recurso de organización que sembrar.
            if (! $hasParam && $schema === null && ! in_array($op['path'], self::AGGREGATING_COLLECTIONS, true)) {
                continue;
            }

            $model = $this->modelFor($schema ?? $op['path']);
            if ($model === null) {
                $uncovered[] = $label.' (esquema '.($schema ?? '¿?').' sin modelo con HasFactory)';

                continue;
            }

            /** @var Model $record */
            $record = TenancyScope::runAs($tenantA, fn () => $model::factory()->create());
            $checked++;

            $response = $this->callAsTenant($test, $tenantB, $op['method'], $this->fillPath($op['path'], $record));

            if (str_contains($op['path'], '{')) {
                if ($response->status() !== 404) {
                    $violations[] = "{$label}: esperaba 404 con un recurso de otra organización, obtuvo {$response->status()}";
                }
            } elseif ($response->isSuccessful() && $this->bodyMentions($response, (string) $record->getKey())) {
                $violations[] = "{$label}: la respuesta de la organización B contiene un recurso de A";
            }

            // Búsqueda: el mismo listado con q= y el id de A no debe devolverlo.
            if ($op['method'] === 'get' && ! str_contains($op['path'], '{')) {
                // Se busca por el nombre visible del recurso (o su id si no tiene).
                $term = (string) ($record->getAttribute('display_name') ?? $record->getAttribute('name') ?? $record->getAttribute('legal_name') ?? $record->getKey());
                $search = $this->callAsTenant($test, $tenantB, 'get', $op['path'].'?q='.urlencode($term));
                if ($search->isSuccessful() && $this->bodyMentions($search, (string) $record->getKey())) {
                    $violations[] = "{$label}?q=: la búsqueda de B devuelve un recurso de A";
                }
            }
        }

        return ['violations' => $violations, 'uncovered' => $uncovered, 'checked' => $checked];
    }

    private function callAsTenant(TestCase $test, string $tenantId, string $method, string $path): TestResponse
    {
        TenancyScope::clear();

        // Usuario de B con todos los permisos y módulos: el único obstáculo debe ser el aislamiento.
        $this->actors[$tenantId] ??= IdentityFixtures::userWithPermissions($tenantId);
        $test->actingAs($this->actors[$tenantId], 'ayni');

        $response = $test->withHeaders([
            'X-Tenant-Id' => $tenantId,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->json(strtoupper($method), '/api/v1'.$path, []);

        TenancyScope::clear();

        return $response;
    }

    /**
     * Esquema del recurso dueño de la ruta: el de la respuesta GET del prefijo
     * hasta el primer parámetro, o el del ítem del listado.
     */
    public function resourceSchema(string $path, string $method): ?string
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $this->spec['paths'] ?? [];

        if (preg_match('#^(.*?\{[a-z_]+\})#', $path, $m) === 1) {
            $resourcePath = $m[1];
            $get = $paths[$resourcePath]['get'] ?? null;

            return is_array($get) ? $this->schemaFromResponse($get) : null;
        }

        $op = $paths[$path]['get'] ?? $paths[$path][$method] ?? null;

        return is_array($op) ? $this->schemaFromResponse($op) : null;
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function schemaFromResponse(array $operation): ?string
    {
        $json = json_encode($operation['responses']['200'] ?? $operation['responses']['201'] ?? []);
        if ($json !== false && preg_match_all('#"\#\\\\/components\\\\/schemas\\\\/([A-Za-z0-9]+)"#', $json, $m) > 0) {
            foreach ($m[1] as $name) {
                if (! in_array($name, ['PageMeta', 'GenericEnvelope', 'Error', 'CursorMeta'], true)) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * @return class-string<Model>|null
     */
    public function modelFor(string $schema): ?string
    {
        $overrides = self::MODEL_OVERRIDES;
        $candidates = isset($overrides[$schema]) ? [$overrides[$schema]] : [];
        foreach (glob(base_path('app/Modules/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            $candidates[] = 'App\\Modules\\'.basename($dir).'\\Infrastructure\\Models\\'.$schema;
        }

        foreach ($candidates as $class) {
            if (class_exists($class) && is_subclass_of($class, Model::class)
                && in_array(HasFactory::class, class_uses_recursive($class), true)) {
                /** @var class-string<Model> $class */
                return $class;
            }
        }

        return null;
    }

    private function fillPath(string $path, Model $record): string
    {
        return (string) preg_replace_callback('#\{([a-z_]+)\}#', function (array $m) use ($record): string {
            $value = $record->getAttribute($m[1]);

            return (string) ($value ?? $record->getKey());
        }, $path);
    }

    private function laravelPath(string $openApiPath): string
    {
        return $openApiPath;
    }

    private function bodyMentions(TestResponse $response, string $needle): bool
    {
        return $needle !== '' && str_contains((string) $response->getContent(), $needle);
    }
}
