<?php

namespace App\Modules\Platform\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * AYU-1 · Artículo de la ayuda del producto: borrador → publicado (cada publicación crea una versión nueva y
 * conserva las anteriores, RN-AYU-02) → archivado (deja de mostrarse en las organizaciones; se puede volver a
 * publicar). Un artículo no se elimina.
 */
final class HelpArticleStateMachine extends StateMachine
{
    public const PUBLISH = 'publish';

    public const ARCHIVE = 'archive';

    public function transitions(): array
    {
        return [
            'draft' => [self::PUBLISH => 'published', self::ARCHIVE => 'archived'],
            'published' => [self::PUBLISH => 'published', self::ARCHIVE => 'archived'],
            'archived' => [self::PUBLISH => 'published'],
        ];
    }
}
