<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\BankingPort;
use Throwable;

final class FakeBankingAdapter implements BankingPort
{
    /**
     * @var array<string, array{batch_id: string, total_amount: float, items: array<int, array{account: string, amount: float, currency: string, recipient: string}>}>
     */
    private array $payouts = [];

    private ?Throwable $nextException = null;

    public function __construct(
        private readonly ?IntegrationCallRecorder $recorder = null,
    ) {}

    public function providerName(): string
    {
        return 'fake_banking';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function failNext(Throwable $e): void
    {
        $this->nextException = $e;
    }

    public function processPayout(string $batchId, float $totalAmount, array $items): string
    {
        $start = hrtime(true);
        $txId = 'tx_bank_'.str()->uuid();

        if ($this->nextException !== null) {
            $e = $this->nextException;
            $this->nextException = null;

            $duration = (hrtime(true) - $start) / 1e6;
            $this->recorder?->record('fake_banking', 'processPayout', $duration, 'failed', $e->getMessage(), ['batch_id' => $batchId]);

            throw $e;
        }

        $this->payouts[$txId] = [
            'batch_id' => $batchId,
            'total_amount' => $totalAmount,
            'items' => $items,
        ];

        $duration = (hrtime(true) - $start) / 1e6;
        $this->recorder?->record('fake_banking', 'processPayout', $duration, 'success', null, [
            'batch_id' => $batchId,
            'total_amount' => $totalAmount,
            'count' => count($items),
        ]);

        return $txId;
    }

    /**
     * @return array<string, array{batch_id: string, total_amount: float, items: array<int, array{account: string, amount: float, currency: string, recipient: string}>}>
     */
    public function getPayouts(): array
    {
        return $this->payouts;
    }
}
