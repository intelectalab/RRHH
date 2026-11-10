<?php

namespace App\Shared\Permissions;

/**
 * Permisos efectivos de un usuario en la organización activa: unión de los permisos
 * de sus roles vigentes, con el alcance más amplio que cada rol concede.
 */
final readonly class EffectivePermissions
{
    /** Orden de amplitud del alcance: el primero es el más amplio. */
    public const SCOPE_ORDER = ['organization', 'legal_entity', 'site', 'org_unit', 'team', 'assigned', 'own'];

    /**
     * @param  array<string, string>  $actions  código => alcance más amplio
     * @param  array<string, string>  $fields  código => alcance más amplio
     * @param  array<int, string>  $systemRoles  códigos de rol de sistema asignados
     */
    public function __construct(
        public array $actions,
        public array $fields,
        public array $systemRoles,
    ) {}

    public function has(string $code): bool
    {
        return isset($this->actions[$code]) || isset($this->fields[$code]);
    }

    public function scopeFor(string $code): ?string
    {
        return $this->actions[$code] ?? $this->fields[$code] ?? null;
    }

    public function isCompanyAdmin(): bool
    {
        return in_array('admin_empresa', $this->systemRoles, true);
    }

    public static function widest(string $a, string $b): string
    {
        $ia = array_search($a, self::SCOPE_ORDER, true);
        $ib = array_search($b, self::SCOPE_ORDER, true);

        return ($ia === false ? PHP_INT_MAX : $ia) <= ($ib === false ? PHP_INT_MAX : $ib) ? $a : $b;
    }
}
