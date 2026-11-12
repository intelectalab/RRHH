<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Application\Email\EmailFeedback;
use App\Modules\Notifications\Application\Email\EmailSenderSettings;
use App\Modules\Notifications\Infrastructure\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOT-3 · Remitente de correo de la organización y reactivación de una dirección que rebotó.
 */
final class EmailSenderController
{
    public function __construct(
        private readonly EmailSenderSettings $settings,
        private readonly EmailFeedback $feedback,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->settings->current()]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:platform,own'],
            'address' => ['nullable', 'required_if:mode,own', 'email:rfc', 'max:254'],
        ]);
        $this->settings->configure((string) $data['mode'], isset($data['address']) ? (string) $data['address'] : null);

        return response()->json(['data' => $this->settings->current()]);
    }

    public function verify(): JsonResponse
    {
        $this->settings->verify();

        return response()->json(['data' => $this->settings->current()]);
    }

    public function clearBounce(string $id): JsonResponse
    {
        /** @var NotificationDelivery $delivery */
        $delivery = NotificationDelivery::query()->findOrFail($id);
        $this->feedback->clearBounce($delivery);

        return response()->json(['data' => ['id' => $delivery->id, 'status' => $delivery->status, 'address_active' => true]]);
    }
}
