<?php

namespace App\Shared\Help;

use App\Shared\Permissions\EffectivePermissions;

/**
 * IAR-9 · Contrato del centro de ayuda (AYU) para el asistente de ayuda: los artículos que el usuario puede
 * abrir (ayuda del producto y de la organización), con su texto. Lo implementa el módulo HelpCenter.
 */
interface HelpCorpus
{
    /**
     * @param  array<int, string>  $modules
     * @return list<array<string, mixed>>
     */
    public function corpus(EffectivePermissions $permissions, array $modules, string $locale): array;
}
