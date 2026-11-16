<?php

/*
 * AYU · Ayuda del producto.
 *  - product_version: versión del producto con la que se publica la ayuda (RN-AYU-02).
 *  - locales: idiomas de la ayuda; fallback_locale: el que se muestra con aviso si falta la traducción (RN-AYU-07).
 */
return [
    'product_version' => env('GESTIA_PRODUCT_VERSION', '1.0'),
    'locales' => ['es', 'en', 'pt'],
    'fallback_locale' => 'es',
];
