<?php

namespace App\Shared\Help;

use App\Shared\Permissions\EffectivePermissions;

/**
 * AYU-3 · Claves de ayuda por pantalla (resources/help/screens.json): el módulo que habilita cada pantalla y
 * los permisos que permiten abrirla. La consola valida contra esta lista las pantallas de un artículo y el
 * centro de ayuda la usa para mostrar solo artículos de pantallas que el usuario puede abrir (RN-AYU-01).
 */
final class HelpScreens
{
    /** @var array<string, array{module: string, permissions: list<string>}>|null */
    private ?array $screens = null;

    /**
     * @return array<string, array{module: string, permissions: list<string>}>
     */
    public function all(): array
    {
        if ($this->screens !== null) {
            return $this->screens;
        }
        $raw = json_decode((string) file_get_contents(resource_path('help/screens.json')), true, 512, JSON_THROW_ON_ERROR);
        $screens = [];
        foreach ((array) ($raw['screens'] ?? []) as $key => $def) {
            $screens[(string) $key] = [
                'module' => (string) ($def['module'] ?? 'base'),
                'permissions' => array_values(array_map('strval', (array) ($def['permissions'] ?? []))),
            ];
        }

        return $this->screens = $screens;
    }

    /**
     * Arreglo de texto de Postgres («{a,b}») ⇄ lista. Roles y claves de pantalla son códigos simples (se
     * validan con una expresión regular antes de guardarse), sin comas ni comillas.
     *
     * @param  list<string>  $values
     */
    public static function toPg(array $values): string
    {
        return '{'.implode(',', $values).'}';
    }

    /**
     * @return list<string>
     */
    public static function fromPg(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $text = trim((string) $value, '{}');

        return $text === '' ? [] : array_map(fn (string $v): string => trim($v, '"'), explode(',', $text));
    }

    public function exists(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    /**
     * Una pantalla se puede abrir si su módulo está activo y el usuario tiene alguno de sus permisos.
     *
     * @param  array<int, string>  $activeModules
     */
    public function canOpen(string $key, EffectivePermissions $permissions, array $activeModules): bool
    {
        $screen = $this->all()[$key] ?? null;
        if ($screen === null || ! in_array($screen['module'], $activeModules, true)) {
            return false;
        }
        if ($screen['permissions'] === []) {
            return true;
        }
        foreach ($screen['permissions'] as $permission) {
            if ($permissions->has($permission)) {
                return true;
            }
        }

        return false;
    }
}
