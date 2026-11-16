<?php

namespace App\Shared\Integrations\Ports;

/**
 * CFG-7 · Fuente externa de tipos de cambio (por ejemplo, la publicación oficial del día). El dominio no
 * llama al proveedor: lo pide por este puerto y guarda lo recibido como un tipo publicado más, con la
 * fuente indicada. En desarrollo y pruebas responde la implementación falsa.
 */
interface ExchangeRatesPort
{
    /**
     * Tipo de $from a $to publicado para la fecha (decimal exacto en texto), o null si la fuente no lo tiene.
     */
    public function rate(string $from, string $to, string $date): ?string;

    public function providerName(): string;

    public function isFake(): bool;
}
