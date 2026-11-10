<?php

namespace App\Shared\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Regla 12 · Todo monto viaja como {amount, currency} con decimal exacto en texto; nunca float.
 */
final readonly class Money implements JsonSerializable
{
    public string $amount;

    public function __construct(string|int $amount, public string $currency)
    {
        $amount = (string) $amount;
        if (preg_match('/^-?\d{1,18}(\.\d{1,8})?$/', $amount) !== 1) {
            throw new InvalidArgumentException("Monto inválido: {$amount}");
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Moneda inválida: {$currency}");
        }
        $this->amount = $amount;
    }

    /**
     * @return array{amount: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
