<?php

namespace App\Shared\Integrations\Ports;

/**
 * SAS-11 · ADR 0010 · Comprobante electrónico del SaaS ante la administración tributaria, a través de un proveedor
 * OSE o PSE (aún por elegir). En desarrollo y pruebas responde la implementación falsa.
 *
 * El proveedor valida y responde con la constancia de aceptación (o el motivo del rechazo). Reenviar el mismo
 * comprobante (misma clave) no emite otro: devuelve el resultado ya conocido.
 */
interface EInvoicingPort
{
    /**
     * @param  array{key: string, series: string, issue_date: string, currency: string, buyer_tax_id: string|null, buyer_name: string,
     *               buyer_country: string, subtotal: string, tax: string, total: string, tax_rate: string|null,
     *               lines: list<array{description: string, quantity: string, unit_price: string, amount: string}>}  $document
     * @return array{accepted: bool, series: string, number: string|null, ticket: string, hash: string|null, message: string}
     */
    public function issue(array $document): array;

    public function providerName(): string;

    public function isFake(): bool;
}
