<?php

namespace App\Shared\Search;

use Illuminate\Http\Request;

/**
 * Fuente de la búsqueda global (UI-7). Cada módulo registra las suyas.
 * La fuente aplica permisos y alcance en su consulta: nunca devuelve lo que el usuario no puede ver.
 */
interface SearchSource
{
    /** Clave del tipo de resultado (user, role, legal_entity…). */
    public function type(): string;

    /** Permiso de acción necesario para consultar esta fuente. */
    public function permission(): string;

    /**
     * @return array<int, array{id: string, title: string, subtitle: string|null, href: string}>
     */
    public function search(string $term, Request $request, int $limit): array;
}
