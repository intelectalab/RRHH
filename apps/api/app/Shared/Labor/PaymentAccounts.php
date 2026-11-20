<?php

namespace App\Shared\Labor;

/**
 * PER-10 · COL-8 · Cuenta de pago vigente de una relación laboral a una fecha (la aprobada y activa). La lee la
 * planilla para armar el lote de pago sin consultar las tablas de Core HR. El número viaja descifrado: quien la
 * use solo puede escribirlo en el archivo bancario confidencial.
 */
interface PaymentAccounts
{
    /**
     * @return array{id: string, bank_code: string, account_type: string, account_number: string, interbank_code: string|null, currency: string|null}|null
     */
    public function accountAt(string $employmentId, string $date, string $purpose = 'salary'): ?array;
}
