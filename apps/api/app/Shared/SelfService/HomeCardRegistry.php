<?php

namespace App\Shared\SelfService;

use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use Illuminate\Support\Facades\DB;

/**
 * ESS-1 · Registro de tarjetas del inicio del colaborador. Igual que la bandeja (WFL-9): solo se
 * consultan las tarjetas con permiso y con el módulo del permiso contratado (RN-ESS-02).
 */
final class HomeCardRegistry
{
    /** @var array<string, HomeCard> */
    private array $cards = [];

    public function __construct(private readonly ModuleGate $modules) {}

    public function register(HomeCard $card): void
    {
        $this->cards[$card->key()] = $card;
    }

    /**
     * @return list<array{key: string, module: string, href: string, data: array<string, mixed>, pending: int}>
     */
    public function collect(string $userId, string $employmentId, EffectivePermissions $permissions): array
    {
        $cards = $this->cards;
        uasort($cards, fn (HomeCard $a, HomeCard $b) => $a->order() <=> $b->order());

        $out = [];
        foreach ($cards as $key => $card) {
            if (! $permissions->has($card->permission())) {
                continue;
            }
            $module = DB::table('permissions')->where('code', $card->permission())->value('module_code');
            if (! is_string($module) || ! $this->modules->isEnabled($module)) {
                continue;
            }
            $built = $card->card($userId, $employmentId);
            if ($built === null) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'module' => $module,
                'href' => $built['href'],
                'data' => $built['data'],
                'pending' => (int) ($built['pending'] ?? 0),
            ];
        }

        return $out;
    }
}
