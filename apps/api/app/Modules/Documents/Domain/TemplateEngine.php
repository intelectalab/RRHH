<?php

namespace App\Modules\Documents\Domain;

/**
 * DOC-4 · Motor de plantillas, sin dependencias: variables {{grupo.variable}} y bloques condicionales
 * {{#if grupo.variable}}…{{else}}…{{/if}} y {{#unless grupo.variable}}…{{/unless}} (se pueden anidar).
 *
 * - Un bloque se muestra si la variable tiene valor (no vacío). Una variable sin valor se imprime como «—».
 * - No evalúa expresiones ni código: solo sustituye texto, así que una plantilla no puede leer nada fuera del
 *   catálogo de variables que se le entrega.
 */
final class TemplateEngine
{
    private const NAME = '[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*';

    private const BLOCK = '/\{\{#(if|unless)\s+('.self::NAME.')\s*\}\}((?:(?!\{\{#(?:if|unless)\s).)*?)\{\{\/\1\}\}/s';

    public const EMPTY = '—';

    /**
     * Variables que usa el cuerpo (en el orden en que aparecen), incluidas las de las condiciones.
     *
     * @return list<string>
     */
    public static function variables(string $body): array
    {
        preg_match_all('/\{\{\s*(?:#(?:if|unless)\s+)?('.self::NAME.')\s*\}\}/', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Errores de sintaxis: bloques sin cerrar o mal anidados y marcas {{…}} que no son variables válidas.
     *
     * @return list<string> fragmentos con el problema
     */
    public static function syntaxErrors(string $body): array
    {
        $rest = $body;
        do {
            $rest = (string) preg_replace_callback(self::BLOCK, fn (array $m): string => str_replace('{{else}}', '', $m[3]), $rest, -1, $count);
        } while ($count > 0);
        $rest = (string) preg_replace('/\{\{\s*'.self::NAME.'\s*\}\}/', '', $rest);
        preg_match_all('/\{\{[^}]*\}\}|\{\{|\}\}/', $rest, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function render(string $body, array $values): string
    {
        $out = $body;
        do {
            $out = (string) preg_replace_callback(self::BLOCK, function (array $m) use ($values): string {
                $parts = explode('{{else}}', $m[3], 2);
                $has = isset($values[$m[2]]) && trim((string) $values[$m[2]]) !== '';
                $show = $m[1] === 'if' ? $has : ! $has;

                return $show ? $parts[0] : ($parts[1] ?? '');
            }, $out, -1, $count);
        } while ($count > 0);

        return (string) preg_replace_callback('/\{\{\s*('.self::NAME.')\s*\}\}/', function (array $m) use ($values): string {
            $v = $values[$m[1]] ?? null;

            return $v === null || trim($v) === '' ? self::EMPTY : $v;
        }, $out);
    }
}
