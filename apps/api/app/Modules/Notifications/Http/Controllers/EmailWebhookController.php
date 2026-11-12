<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Application\Email\EmailFeedback;
use App\Shared\Exceptions\AyniException;
use App\Shared\Integrations\Email\InvalidWebhookSignature;
use App\Shared\Integrations\IntegrationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NOT-3 · El proveedor de correo avisa rebotes, quejas y entregas. La organización sale del dominio
 * (como el resto de la API); sin la firma del proveedor se responde 401 sin leer el contenido.
 */
final class EmailWebhookController
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly EmailFeedback $feedback,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }
        try {
            $events = $this->integrations->email()->parseFeedback((string) $request->getContent(), $headers);
        } catch (InvalidWebhookSignature) {
            throw new AyniException('invalid_signature', 401, (string) trans('notifications.bounce.invalid_signature'));
        }

        return response()->json(['data' => ['received' => count($events), 'applied' => $this->feedback->apply($events)]]);
    }
}
