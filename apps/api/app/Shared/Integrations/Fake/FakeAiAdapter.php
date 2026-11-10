<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\AiPort;

final class FakeAiAdapter implements AiPort
{
    private string $simulatedResponse = 'Simulated AI response for testing and development.';

    public function __construct(
        private readonly ?IntegrationCallRecorder $recorder = null,
    ) {}

    public function providerName(): string
    {
        return 'fake_ai';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function setResponse(string $response): void
    {
        $this->simulatedResponse = $response;
    }

    public function complete(string $prompt, array $options = []): string
    {
        $start = hrtime(true);

        $duration = (hrtime(true) - $start) / 1e6;
        $this->recorder?->record('fake_ai', 'complete', $duration, 'success', null, [
            'prompt_length' => strlen($prompt),
        ]);

        return $this->simulatedResponse;
    }
}
