<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Application\HolidayCalendarService;
use App\Modules\Settings\Infrastructure\Models\HolidayCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CFG-8 · Calendarios de feriados.
 */
final class HolidayCalendarController
{
    public function __construct(private readonly HolidayCalendarService $calendars) {}

    public function index(): JsonResponse
    {
        $this->calendars->ensureNational('PE');
        $rows = HolidayCalendar::query()->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn (HolidayCalendar $c) => $this->calendars->present($c))->all(), 'meta' => ['per_page' => 100, 'next_cursor' => null]]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->calendars->present($this->find($id))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'country' => ['required', 'string', 'size:2', 'exists:countries,code'],
            'site_id' => ['sometimes', 'nullable', 'uuid'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        /** @var array{name: string, country: string, site_id?: string|null, is_default?: bool} $data */
        return response()->json(['data' => $this->calendars->present($this->calendars->create($data))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $calendar = $this->find($id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'is_default' => ['sometimes', 'boolean'],
            'add' => ['sometimes', 'array', 'max:60'],
            'add.*.date' => ['required', 'date_format:Y-m-d'],
            'add.*.name' => ['required', 'string', 'max:120'],
            'add.*.kind' => ['sometimes', 'string'],
            'remove' => ['sometimes', 'array', 'max:60'],
            'remove.*' => ['uuid'],
        ]);

        /** @var array{name?: string, is_default?: bool, add?: array<int, array{date: string, name: string, kind?: string}>, remove?: array<int, string>} $data */
        return response()->json(['data' => $this->calendars->present($this->calendars->update($calendar, $data)->refresh())]);
    }

    private function find(string $id): HolidayCalendar
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/i', $id) === 1, 404);

        /** @var HolidayCalendar */
        return HolidayCalendar::query()->whereKey($id)->firstOrFail();
    }
}
