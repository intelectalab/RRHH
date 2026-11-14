<?php

namespace App\Modules\Documents\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * DOC-1 · DOC-2 · ME-12 Documento del legajo. En base (`documents.status`): draft, pending_approval, active,
 * archived, expired, rejected, requested.
 *
 * - Lo que sube RRHH nace Vigente; lo que sube el propio colaborador en un tipo que exige revisión queda En revisión
 *   y se aprueba o se rechaza con motivo.
 * - «Por vencer» se calcula (no se guarda); la tarea diaria lo pasa a Vencido al llegar la fecha (RN-DOC-09).
 * - Una versión nueva (reemplazar) conserva las anteriores (RN-DOC-01) y vuelve a Vigente o a revisión.
 * - Archivar no borra: el documento sigue consultable y se restaura.
 * Los borradores y las solicitudes (`draft`, `requested`) son de otros flujos (contratos, solicitudes de documento).
 */
final class DocumentStateMachine extends StateMachine
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    public const EXPIRED = 'expired';

    public const REJECTED = 'rejected';

    public const REQUESTED = 'requested';

    public function transitions(): array
    {
        return [
            self::DRAFT => [],
            self::REQUESTED => ['replace' => self::PENDING],
            self::PENDING => ['approve' => self::ACTIVE, 'reject' => self::REJECTED, 'replace' => self::PENDING],
            self::ACTIVE => ['replace' => self::ACTIVE, 'expire' => self::EXPIRED, 'archive' => self::ARCHIVED],
            self::EXPIRED => ['replace' => self::ACTIVE, 'archive' => self::ARCHIVED],
            self::REJECTED => ['replace' => self::PENDING, 'archive' => self::ARCHIVED],
            self::ARCHIVED => ['restore' => self::ACTIVE],
        ];
    }
}
