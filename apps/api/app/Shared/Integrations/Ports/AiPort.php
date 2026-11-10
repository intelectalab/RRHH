<?php

namespace App\Shared\Integrations\Ports;

interface AiPort
{
    /**
     * @param  array<string, mixed>  $options
     * @return string Generated text response
     */
    public function complete(string $prompt, array $options = []): string;

    public function providerName(): string;

    public function isFake(): bool;
}
