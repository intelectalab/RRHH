<?php

return [
    /*
     * Fuera de producción los puertos usan implementaciones falsas. Con 'mailpit', el correo y los
     * SMS/WhatsApp se entregan al buzón local de pruebas (http://localhost:8025) para poder leerlos
     * al probar a mano; las pruebas automáticas siguen con los falsos en memoria.
     */
    'dev_inbox' => env('INTEGRATIONS_DEV_INBOX'),

    /*
     * NOT-5 · Proveedores de SMS/WhatsApp disponibles: nombre (message_channels.provider) → clase que
     * implementa App\Shared\Integrations\Ports\MessagingPort. Agregar un proveedor es registrar aquí su
     * adaptador; los módulos que envían mensajes no cambian.
     */
    'messaging_providers' => [],

    /*
     * NOT-3 · Secreto con el que se firman los avisos de rebote del proveedor de correo que llegan a
     * POST /api/v1/webhooks/email (adaptadores de desarrollo y pruebas; un proveedor real usa su firma).
     */
    'email_webhook_secret' => env('EMAIL_WEBHOOK_SECRET', ''),
];
