<?php

namespace App\Modules\Platform\Application\Web;

use App\Shared\Exceptions\AyniException;
use App\Shared\Integrations\IntegrationManager;
use Illuminate\Validation\ValidationException;

/**
 * WEB-5 a WEB-7 · Antiabuso básico de los formularios públicos: campo trampa invisible (website), verificación
 * antirrobot por el puerto de captcha y consentimiento explícito con su finalidad (RN-WEB-09). El límite de
 * peticiones por IP lo pone la ruta.
 */
final class PublicFormGuard
{
    public function __construct(private readonly IntegrationManager $integrations) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function check(array $input, ?string $ip): void
    {
        if (trim((string) ($input['website'] ?? '')) !== '') {
            throw new AyniException('spam_detected', 422, (string) trans('platform.web.spam'));
        }
        if (! $this->integrations->captcha()->verify((string) ($input['captcha_token'] ?? ''), $ip)) {
            throw ValidationException::withMessages(['captcha_token' => [(string) trans('platform.web.captcha_failed')]]);
        }
        if (($input['consent'] ?? false) !== true) {
            throw ValidationException::withMessages(['consent' => [(string) trans('platform.web.consent_required')]]);
        }
    }
}
