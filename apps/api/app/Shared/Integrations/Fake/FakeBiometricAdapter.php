<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\BiometricPort;

final class FakeBiometricAdapter implements BiometricPort
{
    /**
     * @var array<int, array{device_id: string, user_id: string, timestamp: string, type: string}>
     */
    private array $punches = [];

    public function __construct(
        private readonly ?IntegrationCallRecorder $recorder = null,
    ) {}

    public function providerName(): string
    {
        return 'fake_biometric';
    }

    public function isFake(): bool
    {
        return true;
    }

    /**
     * @param  array<int, array{device_id: string, user_id: string, timestamp: string, type: string}>  $punches
     */
    public function setSimulatedPunches(array $punches): void
    {
        $this->punches = $punches;
    }

    public function pullPunches(string $deviceId, ?string $cursor = null): array
    {
        $start = hrtime(true);

        $result = [
            'punches' => $this->punches,
            'next_cursor' => null,
        ];

        $duration = (hrtime(true) - $start) / 1e6;
        $this->recorder?->record('fake_biometric', 'pullPunches', $duration, 'success', null, [
            'device_id' => $deviceId,
            'count' => count($this->punches),
        ]);

        return $result;
    }
}
