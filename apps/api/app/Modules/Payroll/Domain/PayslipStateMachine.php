<?php

namespace App\Modules\Payroll\Domain;

use App\Shared\StateMachine\StateMachine;

/**
 * ME-20 · Boleta de pago: generada al cerrar el periodo, publicada en el portal, vista por el titular, con acuse
 * cuando la política lo exige y anulada (con motivo) cuando una corrección genera otra versión.
 */
final class PayslipStateMachine extends StateMachine
{
    public const ISSUED = 'generated';

    public const PUBLISHED = 'published';

    public const VIEWED = 'viewed';

    public const ACKNOWLEDGED = 'acknowledged';

    public const VOIDED = 'voided';

    public function transitions(): array
    {
        return [
            self::ISSUED => ['publish' => self::PUBLISHED, 'void' => self::VOIDED],
            self::PUBLISHED => ['open' => self::VIEWED, 'acknowledge' => self::ACKNOWLEDGED, 'void' => self::VOIDED],
            self::VIEWED => ['acknowledge' => self::ACKNOWLEDGED, 'void' => self::VOIDED],
            self::ACKNOWLEDGED => ['void' => self::VOIDED],
            self::VOIDED => [],
        ];
    }
}
