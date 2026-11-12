<?php

/**
 * SAS-6 · Límites y consumo del plan. Cada mensaje de tope explica cómo ampliarlo.
 */
return [
    'metrics' => [
        'employees' => 'Colaboradores activos',
        'admins' => 'Administradores',
        'legal_entities' => 'Empresas activas',
        'storage_mb' => 'Almacenamiento (MB)',
        'api_calls' => 'Llamadas a la API del mes',
    ],
    'reached' => [
        'employees' => 'Tu plan admite hasta :limit colaboradores activos y ya están en uso. Para dar de alta a más personas, amplía el plan o suma un complemento de colaboradores (Configuración → Consumo del plan) o da de baja a quien ya no trabaja.',
        'admins' => 'Tu plan admite hasta :limit administradores. Para dar este rol a otra persona, quítaselo a alguien o amplía el plan desde Configuración → Consumo del plan.',
        'legal_entities' => 'Tu plan admite hasta :limit empresas activas. Para agregar otra, archiva una que ya no uses o amplía el plan desde Configuración → Consumo del plan.',
        'storage_mb' => 'El almacenamiento de tu plan (:limit MB) no alcanza para este archivo. Puedes seguir viendo y descargando lo guardado; para subir más, elimina archivos que no necesites o amplía el plan desde Configuración → Consumo del plan.',
        'default' => 'Llegaste al límite de tu plan (:limit). Amplía el plan o suma un complemento desde Configuración → Consumo del plan.',
    ],
];
