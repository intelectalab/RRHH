<?php

namespace App\Shared\Inbox;

/**
 * WFL-9 · Contrato común para que cada módulo aporte sus pendientes a la bandeja del usuario.
 */
interface InboxSource
{
    /** Tipo de pendiente (approval, task, signature…). */
    public function kind(): string;

    /** Permiso que habilita la fuente; el módulo del permiso debe estar contratado. */
    public function permission(): string;

    /**
     * Pendientes del usuario, del más urgente al menos urgente.
     *
     * @return array<int, array{id: string, kind?: string, type: string, title: string, subtitle: string|null, due_at: string|null, overdue: bool, created_at: string|null, href: string, data?: array<string, mixed>}>
     */
    public function items(string $userId, int $limit): array;

    public function count(string $userId): int;
}
