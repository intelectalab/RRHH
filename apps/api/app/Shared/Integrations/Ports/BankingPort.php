<?php

namespace App\Shared\Integrations\Ports;

interface BankingPort
{
    /**
     * @param  array<int, array{account: string, amount: float, currency: string, recipient: string}>  $items
     * @return string Payout transaction or batch reference ID
     */
    public function processPayout(string $batchId, float $totalAmount, array $items): string;

    public function providerName(): string;

    public function isFake(): bool;
}
