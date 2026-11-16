<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Modules\Imports\Application\CountryPackService;
use App\Modules\Imports\Application\SetupChecklistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * IMP-3 · Primeros pasos e IMP-4 · Plantillas del país. Los pasos cambian de estado solo por acciones
 * (skip, reopen); no hay PATCH de «status» (regla 3).
 */
final class SetupController
{
    public function __construct(
        private readonly SetupChecklistService $checklist,
        private readonly CountryPackService $packs,
    ) {}

    public function checklist(): JsonResponse
    {
        return response()->json(['data' => $this->checklist->checklist()]);
    }

    public function skip(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->checklist->skip($code, $this->userId($request))]);
    }

    public function reopen(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->checklist->reopen($code, $this->userId($request))]);
    }

    public function dismiss(Request $request): JsonResponse
    {
        $data = $request->validate(['hidden' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);

        return response()->json(['data' => $this->checklist->dismiss((bool) $data['hidden'], $data['reason'] ?? null)]);
    }

    public function packs(): JsonResponse
    {
        return response()->json(['data' => $this->packs->available()]);
    }

    public function apply(Request $request, string $code): JsonResponse
    {
        return response()->json(['data' => $this->packs->apply($code, $this->userId($request))]);
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
