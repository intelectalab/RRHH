<?php

namespace App\Shared\Search;

use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Registro de fuentes de búsqueda. Solo consulta las fuentes cuyo permiso tiene el usuario
 * y cuyo módulo está contratado.
 */
final class SearchRegistry
{
    /** @var array<string, SearchSource> */
    private array $sources = [];

    public function __construct(private readonly ModuleGate $modules) {}

    public function register(SearchSource $source): void
    {
        $this->sources[$source->type()] = $source;
    }

    /**
     * @return array<int, array{type: string, id: string, title: string, subtitle: string|null, href: string}>
     */
    public function search(string $term, Request $request, EffectivePermissions $permissions, int $limitPerSource = 5): array
    {
        $results = [];
        foreach ($this->sources as $source) {
            if (! $permissions->has($source->permission())) {
                continue;
            }
            $module = DB::table('permissions')->where('code', $source->permission())->value('module_code');
            if (! is_string($module) || ! $this->modules->isEnabled($module)) {
                continue;
            }
            foreach ($source->search($term, $request, $limitPerSource) as $hit) {
                $results[] = ['type' => $source->type()] + $hit;
            }
        }

        return $results;
    }

    /**
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->sources);
    }
}
