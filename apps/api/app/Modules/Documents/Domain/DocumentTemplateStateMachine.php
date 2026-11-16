<?php

namespace App\Modules\Documents\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * DOC-4 · Plantilla de documentos. En base: draft, active (publicada), inactive (retirada).
 *
 * - Publicar fija el borrador como versión nueva y la deja como vigente; una publicada se vuelve a publicar
 *   cuando su borrador cambió (versión siguiente). Una retirada se vuelve a publicar.
 * - Retirar deja de ofrecerla para generar; los documentos ya generados conservan su versión (RN-DOC-11).
 */
final class DocumentTemplateStateMachine extends StateMachine
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public function transitions(): array
    {
        return [
            self::DRAFT => ['publish' => self::ACTIVE],
            self::ACTIVE => ['publish' => self::ACTIVE, 'retire' => self::INACTIVE],
            self::INACTIVE => ['publish' => self::ACTIVE],
        ];
    }
}
