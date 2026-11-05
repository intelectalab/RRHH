<?php

namespace Tests\Support;

use App\Shared\Tenancy\TenancyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait TenantTestHelper
{
    /**
     * Crea un tenant de prueba y configura TenancyScope y la variable de sesión RLS de PostgreSQL.
     */
    protected function createAndBindTenant(string $name = 'Empresa de Prueba'): string
    {
        $tenantId = (string) Str::uuid();
        $slug = 'test-'.Str::random(8);

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'slug' => $slug,
            'name' => $name,
            'status' => 'active',
            'country_code' => 'PE',
            'default_currency' => 'PEN',
            'default_locale' => 'es',
            'timezone' => 'America/Lima',
            'is_demo' => false,
            'is_partner_managed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TenancyScope::set($tenantId);
        DB::statement("SELECT set_config('app.tenant_id', ?, false)", [$tenantId]);

        return $tenantId;
    }
}
