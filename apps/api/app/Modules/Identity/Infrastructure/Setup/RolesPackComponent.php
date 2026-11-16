<?php

namespace App\Modules\Identity\Infrastructure\Setup;

use App\Shared\Setup\CountryPackComponent;
use Illuminate\Support\Facades\DB;

/**
 * IMP-4 · Roles sugeridos: los roles de sistema con sus permisos por defecto. Se crean al dar de alta la
 * organización (seed_tenant_defaults), así que esta parte solo declara cuáles son y cuántos hay; sus
 * permisos se ajustan, con motivo, en Configuración → Roles.
 */
final class RolesPackComponent implements CountryPackComponent
{
    public function code(): string
    {
        return 'roles';
    }

    public function moduleCode(): string
    {
        return 'base';
    }

    public function items(string $country): array
    {
        /** @var list<string> */
        return DB::table('system_roles')->where('realm', 'tenant')->orderBy('name')->pluck('name')->map(fn ($n) => (string) $n)->all();
    }

    public function present(string $country): int
    {
        return DB::table('roles')->whereNotNull('system_role_code')->whereNull('deleted_at')->count();
    }

    public function apply(string $country, ?string $userId): void {}

    public function link(): string
    {
        return '/configuracion/roles';
    }
}
