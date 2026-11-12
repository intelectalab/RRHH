<?php

namespace App\Shared\Features;

use App\Shared\Cache\CacheVersions;
use App\Shared\Cache\TenantCache;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * SAS-7 · Banderas de funcionalidad de la organización activa.
 *
 * La evaluación vive en la base (función current_feature_flags(), RN-SAS-07: organización, plan, grupo
 * beta, país; luego porcentaje gradual y valor por defecto). La API la consulta aquí y la web la recibe en
 * /me → features: las dos ven lo mismo.
 *
 * Se guarda en caché por organización con una versión global: cualquier cambio en la consola sube la
 * versión y el siguiente pedido ya evalúa de nuevo (sin desplegar). El tiempo de vida corto cubre los
 * cambios que no pasan por la consola (vencimiento de una activación, cambio de plan).
 */
final class FeatureFlags
{
    private const VERSION = 'feature_flags';

    private const TTL_SECONDS = 60;

    /** @var array<string, array<string, array{enabled: bool, source: string}>> */
    private array $memo = [];

    public function __construct(
        private readonly TenantCache $cache,
        private readonly CacheVersions $versions,
    ) {}

    /**
     * @return array<string, array{enabled: bool, source: string}>
     */
    public function all(): array
    {
        $tenantId = TenancyScope::current();
        if ($tenantId === null) {
            return [];
        }
        $version = $this->versions->current(self::VERSION);
        $key = $tenantId.':'.$version;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        /** @var array<string, array{enabled: bool, source: string}> $flags */
        $flags = $this->cache->forTenant($tenantId)->remember('feature_flags:'.$version, self::TTL_SECONDS, function (): array {
            $out = [];
            foreach (DB::select('SELECT code, enabled, source FROM current_feature_flags() ORDER BY code') as $row) {
                $out[(string) $row->code] = ['enabled' => (bool) $row->enabled, 'source' => (string) $row->source];
            }

            return $out;
        });

        return $this->memo[$key] = $flags;
    }

    public function enabled(string $code): bool
    {
        return ($this->all()[$code]['enabled'] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    public function enabledCodes(): array
    {
        return array_keys(array_filter($this->all(), fn (array $f): bool => $f['enabled']));
    }

    /** Olvida lo memorizado en la petición (la caché compartida sigue valiendo). */
    public function forget(): void
    {
        $this->memo = [];
    }

    /** Invalida la evaluación de todas las organizaciones (cambio de bandera, regla, grupo beta o plan). */
    public function bumpVersion(): void
    {
        $this->versions->bump(self::VERSION);
        $this->memo = [];
    }
}
