<?php

namespace App\Shared\Documents;

/** ESS-4 · Implementación por defecto: sin plantillas, toda constancia se arma con SimplePdf. */
final class NoCertificateTemplates implements CertificateTemplates
{
    public function render(string $kind, array $variables, string $locale): ?array
    {
        return null;
    }
}
