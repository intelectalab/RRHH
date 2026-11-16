<?php

namespace App\Shared\Documents;

/**
 * DOC-4 · Puerto del motor de plantillas para otros módulos (p. ej. Contratos, CON-2): resuelve la versión
 * publicada de una plantilla de la organización con las variables seguras de una relación laboral, sin que el
 * módulo importe las clases del módulo documental. Lo implementa el módulo Documents.
 */
interface TemplateRenderer
{
    /**
     * Título y cuerpo resueltos de la versión publicada, o null si la plantilla no existe o no está publicada.
     * $overrides fija variables del catálogo con datos del trámite (p. ej. contrato.inicio del contrato que se
     * genera, no del vigente).
     *
     * @param  array<string, string|null>  $overrides
     * @return array{title: string, body: string, template_version_id: string}|null
     */
    public function renderPublished(string $templateId, string $employmentId, array $overrides = []): ?array;
}
