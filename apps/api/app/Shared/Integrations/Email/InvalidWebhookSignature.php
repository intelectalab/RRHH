<?php

namespace App\Shared\Integrations\Email;

use RuntimeException;

/** La llamada del proveedor no trae una firma válida: se rechaza sin leer su contenido. */
final class InvalidWebhookSignature extends RuntimeException {}
