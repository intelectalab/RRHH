<?php

namespace App\Shared\Documents;

/**
 * ESS-4 · Punto de extensión para que las constancias usen las plantillas del legajo (DOC-3/4/5) cuando
 * existan. El módulo de plantillas lo implementa y lo registra en su ServiceProvider; mientras no haya
 * plantilla para un tipo, render() devuelve null y la constancia se arma con SimplePdf.
 *
 * Las variables ya vienen resueltas por el módulo de constancias con los permisos aplicados (la de
 * remuneración solo llega en la constancia de remuneraciones), así que la plantilla solo da forma.
 */
interface CertificateTemplates
{
    /**
     * @param  string  $kind  employment_certificate | income_certificate | vacation_certificate
     * @param  array<string, string>  $variables  employee_name, employee_code, legal_entity_name, legal_entity_tax_id, job_title, hire_date, termination_date, issued_on, salary, vacation_periods, purpose…
     * @return array{contents: string, mime: string, extension: string}|null
     */
    public function render(string $kind, array $variables, string $locale): ?array;
}
