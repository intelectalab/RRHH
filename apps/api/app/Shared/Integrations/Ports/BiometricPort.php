<?php

namespace App\Shared\Integrations\Ports;

interface BiometricPort
{
    /**
     * @return array{punches: array<int, array{device_id: string, user_id: string, timestamp: string, type: string}>, next_cursor: ?string}
     */
    public function pullPunches(string $deviceId, ?string $cursor = null): array;

    public function providerName(): string;

    public function isFake(): bool;
}
