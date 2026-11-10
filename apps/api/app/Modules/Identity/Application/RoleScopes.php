<?php

namespace App\Modules\Identity\Application;

/**
 * Traduce el alcance entre el contrato de la API y la base de datos.
 */
final class RoleScopes
{
    /** @var array<string, string> API => base */
    private const MAP = [
        'organization' => 'organization',
        'legal_entity' => 'legal_entity',
        'site' => 'site',
        'unit' => 'org_unit',
        'team' => 'team',
        'self' => 'own',
    ];

    /** @return array<int, string> */
    public static function apiValues(): array
    {
        return array_keys(self::MAP);
    }

    public static function toDb(string $api): string
    {
        return self::MAP[$api] ?? 'organization';
    }

    public static function toApi(string $db): string
    {
        $flipped = array_flip(self::MAP);

        return $flipped[$db] ?? 'organization';
    }
}
