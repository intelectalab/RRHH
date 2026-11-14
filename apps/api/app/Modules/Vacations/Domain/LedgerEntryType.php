<?php

namespace App\Modules\Vacations\Domain;

/**
 * VAC-2 · Tipos de movimiento del libro de vacaciones y su signo (RN-VAC-02). El saldo disponible es la
 * suma de todos los movimientos; cada columna del saldo agrupa los suyos (un contraasiento cuenta en la
 * columna del movimiento que revierte).
 */
final class LedgerEntryType
{
    public const ACCRUAL = 'accrual';

    public const OPENING_BALANCE = 'opening_balance';

    public const ADJUSTMENT = 'adjustment';

    public const SCHEDULED = 'scheduled';

    public const TAKEN = 'taken';

    public const SOLD = 'sold';

    public const SETTLEMENT = 'settlement';

    public const EXPIRED = 'expired';

    public const REVERSAL = 'reversal';

    /** Columnas del saldo: generadas, ajustadas, programadas, tomadas, vendidas, liquidadas. */
    public const COLUMNS = [
        self::ACCRUAL => 'generated',
        self::OPENING_BALANCE => 'generated',
        self::ADJUSTMENT => 'adjusted',
        self::SCHEDULED => 'scheduled',
        self::TAKEN => 'taken',
        self::SOLD => 'sold',
        self::SETTLEMENT => 'settled',
        self::EXPIRED => 'settled',
    ];
}
