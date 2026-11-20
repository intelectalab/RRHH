<?php

namespace App\Shared\Integrations\Fake;

use App\Shared\Integrations\Logging\IntegrationCallRecorder;
use App\Shared\Integrations\Ports\CaptchaPort;

/**
 * Captcha falso para desarrollo y pruebas: acepta cualquier token salvo «fail».
 */
final class FakeCaptchaAdapter implements CaptchaPort
{
    public function __construct(private readonly ?IntegrationCallRecorder $recorder = null) {}

    public function verify(string $token, ?string $ip): bool
    {
        $ok = $token !== 'fail';
        $this->recorder?->record('fake_captcha', 'verify', 0.0, $ok ? 'success' : 'failed', null, []);

        return $ok;
    }

    public function providerName(): string
    {
        return 'fake';
    }
}
