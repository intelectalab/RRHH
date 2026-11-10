<?php

namespace Database\Seeders;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Models\UserIdentity;
use App\Shared\Security\BlindIndex;
use App\Shared\Tenancy\TenancyScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Organización demo para desarrollo: se entra por http://demo.localhost:3000.
 *
 * La clave del administrador no está en el código: se toma de DEMO_ADMIN_PASSWORD
 * o se genera al azar y se muestra una sola vez en la consola.
 */
class DemoOrganizationSeeder extends Seeder
{
    public const SLUG = 'demo';

    public function run(): void
    {
        $tenantId = DB::table('tenants')->where('slug', self::SLUG)->value('id');
        if ($tenantId === null) {
            $tenantId = (string) Str::uuid();
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'slug' => self::SLUG,
                'name' => 'Organización Demo',
                'status' => 'active',
                'country_code' => 'PE',
                'default_currency' => 'PEN',
                'is_demo' => true,
            ]);
        }

        // Roles de sistema, sus permisos por defecto, política de seguridad y módulos base.
        DB::select('SELECT seed_tenant_defaults(?)', [$tenantId]);

        $email = (string) env('DEMO_ADMIN_EMAIL', 'admin@demo.aynihr.test');
        $password = env('DEMO_ADMIN_PASSWORD');
        $generated = ! is_string($password) || $password === '';
        $password = $generated ? Str::password(16) : $password;

        $created = TenancyScope::runAs((string) $tenantId, function () use ($email, $password): bool {
            $adminRole = Role::query()->where('system_role_code', 'admin_empresa')->firstOrFail();

            /** @var UserIdentity|null $existing */
            $existing = UserIdentity::query()
                ->where('kind', 'email')
                ->where('identifier_hash', BlindIndex::for('email', $email))
                ->first();
            if ($existing !== null) {
                // Idempotente: un administrador sembrado antes de existir los roles recibe el suyo.
                if (! $existing->user->roleAssignments()->where('role_id', $adminRole->id)->exists()) {
                    $existing->user->roleAssignments()->create(['role_id' => $adminRole->id]);
                }

                return false;
            }

            $user = User::create([
                'display_name' => 'Administrador Demo',
                'status' => User::STATUS_ACTIVE,
                'locale' => 'es',
                'password_hash' => Hash::make($password),
                'password_changed_at' => now(),
            ]);
            $user->identities()->create(UserIdentity::attributesFor('email', $email) + ['is_primary' => true]);

            $user->roleAssignments()->create(['role_id' => $adminRole->id]);

            return true;
        });

        if ($created && $this->command !== null) {
            $this->command->info("Organización demo: http://demo.localhost:3000 · usuario {$email}");
            if ($generated) {
                $this->command->warn("Clave generada (se muestra una sola vez): {$password}");
            }
        }
    }
}
