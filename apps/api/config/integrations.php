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
];
