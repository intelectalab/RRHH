<?php

namespace App\Shared\Home;

use App\Shared\Permissions\DataScope;
use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Tenancy\TenancyScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Contexto con el que cada tarjeta calcula su número.
 *
 * - Tablero de RRHH (hr): las personas del alcance del usuario para el permiso de la tarjeta (SEG-10).
 * - Tablero del manager (manager): solo su equipo según la línea de reporte vigente (RN-MSS-01), aunque
 *   el usuario tenga además un alcance más amplio por otro rol.
 */
final class HomeContext
{
    /**
     * @param  list<string>  $teamEmploymentIds  relaciones del equipo (solo en el tablero del manager)
     */
    public function __construct(
        public readonly string $audience,
        public readonly string $userId,
        public readonly Request $request,
        public readonly EffectivePermissions $permissions,
        public readonly CarbonImmutable $today,
        public readonly array $teamEmploymentIds,
        private readonly DataScope $scope,
    ) {}

    public function isManager(): bool
    {
        return $this->audience === HomeCard::MANAGER;
    }

    public function can(string $permission): bool
    {
        return $this->permissions->has($permission);
    }

    /**
     * Restringe una consulta a las relaciones laborales que el tablero puede contar.
     *
     * @template TBuilder of EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function restrict(EloquentBuilder|QueryBuilder $query, string $column, string $permission): EloquentBuilder|QueryBuilder
    {
        if ($this->isManager()) {
            $query->whereIn($column, $this->teamEmploymentIds === [] ? ['00000000-0000-0000-0000-000000000000'] : $this->teamEmploymentIds);

            return $query;
        }
        $this->scope->applyEmployment($query, $this->request, $permission, $column);
        $selected = DataScope::selectedLegalEntity($this->request);
        if ($selected !== null) {
            // La empresa elegida en la cabecera acota el tablero (el alcance ya validó que puede verla).
            $query->whereRaw("{$column} IN (SELECT id FROM employments WHERE legal_entity_id = ?)", [$selected]);
        }

        return $query;
    }

    /**
     * Alcance por empresa (registros que no son de una persona, como las posiciones). Solo para el
     * tablero de RRHH; el del manager filtra por su línea de reporte en cada tarjeta.
     *
     * @template TBuilder of EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public function restrictLegalEntity(EloquentBuilder|QueryBuilder $query, string $column, string $permission): EloquentBuilder|QueryBuilder
    {
        return $this->scope->applyLegalEntity($query, $this->request, $permission, $column);
    }

    /**
     * Regla 7 · Una tarjeta que lee un campo sensible (sueldos agregados, fechas de nacimiento) deja su fila
     * en el registro de accesos, aunque muestre un total.
     *
     * @param  list<string>  $fields
     */
    public function logSensitiveRead(string $category, array $fields, string $card): void
    {
        DB::table('data_access_logs')->insert([
            'tenant_id' => TenancyScope::requireTenant(),
            'user_id' => $this->userId,
            'access_kind' => 'api',
            'data_category' => $category,
            'subject_person_id' => null,
            'entity_type' => 'home_card',
            'entity_id' => null,
            'fields' => '{'.implode(',', $fields).'}',
            'purpose' => $this->audience.':'.$card,
            'ip' => $this->request->ip(),
            'occurred_at' => now()->format('Y-m-d H:i:s.uP'),
        ]);
    }

    /** Ruta de la interfaz con el filtro del tablero (el del manager abre su equipo). */
    public function link(string $hrPath, string $managerPath): string
    {
        return $this->isManager() ? $managerPath : $hrPath;
    }
}
