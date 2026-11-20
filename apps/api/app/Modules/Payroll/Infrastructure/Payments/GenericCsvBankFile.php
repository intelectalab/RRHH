<?php

namespace App\Modules\Payroll\Infrastructure\Payments;

use App\Shared\Payroll\BankFileFormat;

/**
 * PER-10 · ADR 0020 · Formato genérico del lote de pago: una cabecera con la empresa, el banco, la fecha y el total, y
 * una fila por abono con la cuenta y el importe. Sirve para cargarlo en la banca por internet de cualquier banco que
 * acepte una hoja de abonos; los diseños propios de cada banco quedan «por validar».
 */
final class GenericCsvBankFile implements BankFileFormat
{
    public function code(): string
    {
        return 'generic_csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function mime(): string
    {
        return 'text/csv';
    }

    public function render(array $header, array $items): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fputcsv($out, ['batch_id', 'tax_id', 'company', 'bank_code', 'payment_date', 'currency', 'total', 'records'], ',', '"', '');
        fputcsv($out, [$header['batch_id'], $header['legal_entity_tax_id'] ?? '', $header['legal_entity_name'] ?? '', $header['bank_code'] ?? '',
            $header['payment_date'], $header['currency'], $header['total'], (string) count($items)], ',', '"', '');
        fputcsv($out, ['employee_code', 'document_number', 'name', 'account_type', 'account_number', 'interbank_code', 'amount', 'currency', 'reference'], ',', '"', '');
        foreach ($items as $i) {
            fputcsv($out, [$i['employee_code'] ?? '', $i['document_number'] ?? '', $i['name'], $i['account_type'], $i['account_number'],
                $i['interbank_code'] ?? '', $i['amount'], $i['currency'], $i['reference']], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
