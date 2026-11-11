<?php

namespace App\Modules\Platform\Application\Console;

use App\Modules\Platform\Infrastructure\Models\PlatformUser;
use Illuminate\Support\Facades\DB;

/**
 * Roles internos de la consola (M-SAS §5) y sus permisos `platform.*` del catálogo.
 * El rol vive en platform_users.role; el mapa rol → permisos, en config/platform.php.
 */
final class ConsoleRoles
{
    /** @var list<string>|null */
    private ?array $catalog = null;

    /**
     * @return list<string>
     */
    public function permissionsFor(PlatformUser $user): array
    {
        /** @var array<string, list<string>> $roles */
        $roles = (array) config('platform.roles', []);
        $granted = $roles[$user->role] ?? [];
        $catalog = $this->catalog();
        if (in_array('*', $granted, true)) {
            return $catalog;
        }

        return array_values(array_intersect($catalog, $granted));
    }

    public function can(PlatformUser $user, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($user), true);
    }

    /**
     * Permisos de la consola que existen en el catálogo.
     *
     * @return list<string>
     */
    public function catalog(): array
    {
        if ($this->catalog === null) {
            /** @var list<string> $codes */
            $codes = DB::table('permissions')->where('code', 'like', 'platform.%')->orderBy('code')->pluck('code')->all();
            $this->catalog = $codes;
        }

        return $this->catalog;
    }
}
