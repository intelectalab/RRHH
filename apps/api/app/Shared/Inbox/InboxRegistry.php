<?php

namespace App\Shared\Inbox;

use App\Shared\Permissions\EffectivePermissions;
use App\Shared\Permissions\ModuleGate;
use Illuminate\Support\Facades\DB;

/**
 * Registro de fuentes de la bandeja «Pendientes». Solo consulta las fuentes con permiso y módulo contratado.
 */
final class InboxRegistry
{
    /** @var array<string, InboxSource> */
    private array $sources = [];

    public function __construct(private readonly ModuleGate $modules) {}

    public function register(InboxSource $source): void
    {
        $this->sources[$source->kind()] = $source;
    }

    /**
     * @return array{total: int, counts: array<string, int>, items: array<int, array<string, mixed>>}
     */
    public function collect(string $userId, EffectivePermissions $permissions, int $limit = 50): array
    {
        $counts = [];
        $items = [];
        foreach ($this->sources as $kind => $source) {
            if (! $permissions->has($source->permission())) {
                continue;
            }
            $module = DB::table('permissions')->where('code', $source->permission())->value('module_code');
            if (! is_string($module) || ! $this->modules->isEnabled($module)) {
                continue;
            }
            $counts[$kind] = $source->count($userId);
            foreach ($source->items($userId, $limit) as $item) {
                $items[] = ['kind' => $kind] + $item;
            }
        }

        // Vencidos primero; luego por vencimiento y antigüedad.
        usort($items, fn ($a, $b) => [! $a['overdue'], $a['due_at'] ?? '9999', $a['created_at'] ?? ''] <=> [! $b['overdue'], $b['due_at'] ?? '9999', $b['created_at'] ?? '']);

        return ['total' => array_sum($counts), 'counts' => $counts, 'items' => array_slice($items, 0, $limit)];
    }
}
