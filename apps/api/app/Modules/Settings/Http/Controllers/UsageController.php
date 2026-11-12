<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Shared\Usage\UsageLimits;
use Illuminate\Http\JsonResponse;

/**
 * SAS-6 · GET /usage · Consumo de la organización frente a los límites de su plan (Configuración → Consumo
 * del plan). Solo cifras: ni sueldos, ni fichas, ni documentos.
 */
final class UsageController
{
    public function __construct(private readonly UsageLimits $usage) {}

    public function index(): JsonResponse
    {
        $metrics = array_map(fn (array $m): array => $m + ['label' => $this->label($m['metric'])], $this->usage->summary());

        return response()->json(['data' => [
            'metrics' => $metrics,
            'alert_percent' => $this->usage->alertPercent(),
        ]]);
    }

    private function label(string $metric): string
    {
        $key = 'usage.metrics.'.$metric;
        $label = trans($key);

        return is_string($label) && $label !== $key ? $label : $metric;
    }
}
