<?php

namespace App\Modules\HelpCenter\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * AYU-4 · Artículo de la organización: borrador → publicado (cada publicación con cambios crea una versión
 * nueva) → por revisar (lo marca la tarea diaria al acercarse la fecha de revisión, RN-AYU-04; sigue visible)
 * → publicado otra vez al revisarlo. Archivar lo retira de la ayuda; se puede volver a publicar. No se elimina.
 */
final class OrgArticleStateMachine extends StateMachine
{
    public const PUBLISH = 'publish';

    public const ARCHIVE = 'archive';

    public const REVIEW = 'review';

    public function transitions(): array
    {
        return [
            'draft' => [self::PUBLISH => 'published', self::ARCHIVE => 'archived'],
            'published' => [self::PUBLISH => 'published', self::REVIEW => 'in_review', self::ARCHIVE => 'archived'],
            'in_review' => [self::PUBLISH => 'published', self::ARCHIVE => 'archived'],
            'archived' => [self::PUBLISH => 'published'],
        ];
    }

    /** @return list<string> */
    public function userActions(string $status): array
    {
        return array_values(array_filter(array_keys($this->transitions()[$status] ?? []), fn (string $a): bool => $a !== self::REVIEW));
    }
}
