<?php

namespace App\Shared\Home;

use App\Shared\Permissions\ModuleGate;
use Illuminate\Support\Facades\DB;

/**
 * Registro de tarjetas de los tableros de inicio. Una tarjeta aparece solo si su módulo está contratado
 * (por el módulo de su permiso) y el usuario tiene ese permiso; las de módulos no contratados no se
 * calculan ni viajan.
 */
final class HomeRegistry
{
    /** @var array<string, HomeCard> */
    private array $cards = [];

    public function __construct(private readonly ModuleGate $modules) {}

    public function register(HomeCard $card): void
    {
        $this->cards[$card->key()] = $card;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collect(HomeContext $context): array
    {
        $cards = array_filter($this->cards, fn (HomeCard $c): bool => in_array($context->audience, $c->audiences(), true));
        uasort($cards, fn (HomeCard $a, HomeCard $b): int => [$a->order(), $a->key()] <=> [$b->order(), $b->key()]);

        $modules = DB::table('permissions')->whereIn('code', array_map(fn (HomeCard $c) => $c->permission(), $cards))->pluck('module_code', 'code')->all();
        $out = [];
        foreach ($cards as $card) {
            $module = $modules[$card->permission()] ?? null;
            if (! is_string($module) || ! $this->modules->isEnabled($module) || ! $context->can($card->permission())) {
                continue;
            }
            $result = $card->compute($context);
            if ($result === null) {
                continue;
            }
            $out[] = ['key' => $card->key(), 'module' => $module] + $result + ['tone' => null, 'items' => [], 'meta' => (object) []];
        }

        return $out;
    }
}
