<?php

namespace App\Shared\Payroll;

/**
 * PER-10 · ADR 0020 · Formato de archivo bancario de un lote de pago. Cada banco tiene su diseño de registro; el
 * primer lanzamiento trae un formato genérico (CSV) y los diseños propios de cada banco se agregan como otras
 * implementaciones cuando se validan con el banco.
 */
interface BankFileFormat
{
    public function code(): string;

    public function extension(): string;

    public function mime(): string;

    /**
     * @param  array{batch_id: string, legal_entity_tax_id: string|null, legal_entity_name: string|null, bank_code: string|null, payment_date: string, currency: string, total: string}  $header
     * @param  list<array{employee_code: string|null, document_number: string|null, name: string, account_type: string, account_number: string, interbank_code: string|null, amount: string, currency: string, reference: string}>  $items
     */
    public function render(array $header, array $items): string;
}
