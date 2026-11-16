<?php

namespace App\Modules\Imports\Application;

use App\Shared\Audit\AuditLogger;
use App\Shared\Exceptions\AyniException;
use App\Shared\Outbox\DomainEventTypes;
use App\Shared\Outbox\OutboxService;
use App\Shared\Permissions\ModuleGate;
use App\Shared\Setup\CountryPackRegistry;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;

/**
 * IMP-4 · Paquetes de configuración inicial del país (packages/country-xx/data/paquete.php).
 *
 * - El paquete declara sus partes; cada parte la siembra su módulo dueño (CountryPackComponent) y dice qué
 *   crea y cuánto de eso ya existe en la organización.
 * - Aplicar es idempotente: solo crea lo que falta, no pisa lo que la organización editó y deja todo
 *   editable en la pantalla real del módulo. Una parte de un módulo no contratado no se aplica.
 * - Queda registrada la versión instalada (tenant_country_packs), con auditoría y el evento
 *   country_pack.applied en la misma transacción.
 */
final class CountryPackService
{
    public function __construct(
        private readonly CountryPackRegistry $registry,
        private readonly ModuleGate $modules,
        private readonly AuditLogger $audit,
        private readonly OutboxService $outbox,
    ) {}

    /**
     * Paquetes disponibles para el país de la organización, con su contenido y lo instalado.
     *
     * @return list<array<string, mixed>>
     */
    public function available(): array
    {
        $country = $this->country();
        $manifest = self::manifest($country);

        return $manifest === null ? [] : [$this->present($country, $manifest)];
    }

    /**
     * @return array{pack: array<string, mixed>, created: array<string, int>}
     */
    public function apply(string $code, ?string $userId): array
    {
        $country = $this->country();
        $manifest = self::manifest($country);
        if ($manifest === null || $manifest['code'] !== $code) {
            throw new AyniException('not_found', 404);
        }

        $created = DB::transaction(function () use ($country, $manifest, $userId): array {
            $before = DB::table('tenant_country_packs')->where('country_pack_code', $manifest['code'])->whereNull('legal_entity_id')->first();
            $created = [];
            foreach ($manifest['components'] as $part) {
                $component = $this->registry->get($part);
                if ($component === null || ! $this->modules->isEnabled($component->moduleCode())) {
                    continue;
                }
                $was = $component->present($country);
                $component->apply($country, $userId);
                $created[$part] = max(0, $component->present($country) - $was);
            }
            $values = ['installed_version' => $manifest['version'], 'installed_at' => now(), 'status' => 'active', 'updated_at' => now()];
            if ($before instanceof \stdClass) {
                DB::table('tenant_country_packs')->where('id', $before->id)->update($values);
                $id = (string) $before->id;
            } else {
                $id = (string) DB::table('tenant_country_packs')->insertGetId(['tenant_id' => TenancyScope::requireTenant(),
                    'country_pack_code' => $manifest['code'], 'legal_entity_id' => null] + $values);
            }
            $this->audit->record('country_pack.applied', 'tenant_country_pack', $id,
                $before instanceof \stdClass ? ['installed_version' => $before->installed_version] : null,
                ['country_pack_code' => $manifest['code'], 'installed_version' => $manifest['version'], 'created' => $created]);
            $this->outbox->record(DomainEventTypes::COUNTRY_PACK_APPLIED, 'tenant_country_pack', $id,
                ['country_pack_code' => $manifest['code'], 'version' => $manifest['version'], 'created' => $created]);

            return $created;
        });

        return ['pack' => $this->present($country, $manifest), 'created' => $created];
    }

    /**
     * @return array{code: string, version: string, released_on: string, components: list<string>, changelog: array<string, string>, por_validar: list<string>}|null
     */
    public static function manifest(string $country): ?array
    {
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return null;
        }
        $file = base_path('packages/country-'.strtolower($country).'/data/paquete.php');
        if (! is_file($file)) {
            return null;
        }

        /** @var array{code: string, version: string, released_on: string, components: list<string>, changelog: array<string, string>, por_validar: list<string>} */
        return require $file;
    }

    /**
     * @param  array{code: string, version: string, released_on: string, components: list<string>, changelog: array<string, string>, por_validar: list<string>}  $manifest
     * @return array<string, mixed>
     */
    private function present(string $country, array $manifest): array
    {
        $installed = DB::table('tenant_country_packs')->where('country_pack_code', $manifest['code'])->whereNull('legal_entity_id')->first();
        $components = [];
        foreach ($manifest['components'] as $part) {
            $component = $this->registry->get($part);
            if ($component === null) {
                continue;
            }
            $items = $component->items($country);
            $components[] = [
                'code' => $part,
                'name' => (string) trans('imports.packs.components.'.$part),
                'module_code' => $component->moduleCode(),
                'module_enabled' => $this->modules->isEnabled($component->moduleCode()),
                'items' => $items,
                'total' => count($items),
                'present' => $component->present($country),
                'link' => $component->link(),
            ];
        }

        return [
            'code' => $manifest['code'],
            'name' => (string) trans('imports.packs.names.'.$manifest['code']),
            'country_code' => $country,
            'version' => $manifest['version'],
            'released_on' => $manifest['released_on'],
            'changelog' => $manifest['changelog'],
            'pending_validation' => $manifest['por_validar'],
            'installed_version' => $installed instanceof \stdClass ? (string) $installed->installed_version : null,
            'installed_at' => $installed instanceof \stdClass ? (string) $installed->installed_at : null,
            'update_available' => $installed instanceof \stdClass && version_compare((string) $installed->installed_version, $manifest['version'], '<'),
            'components' => $components,
        ];
    }

    private function country(): string
    {
        return (string) (DB::table('current_tenant')->value('country_code') ?? '');
    }
}
