<?php

use App\Modules\Settings\Http\Controllers\CatalogController;
use App\Modules\Settings\Http\Controllers\CustomFieldController;
use App\Modules\Settings\Http\Controllers\ExchangeRateController;
use App\Modules\Settings\Http\Controllers\HolidayCalendarController;
use App\Modules\Settings\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'groups'])->middleware('permission:settings.parameters.view');
    Route::get('/settings/{group}', [SettingsController::class, 'show'])->middleware('permission:settings.parameters.view');
    Route::put('/settings/{group}', [SettingsController::class, 'update'])->middleware('permission:settings.parameters.update');

    Route::get('/holiday-calendars', [HolidayCalendarController::class, 'index'])->middleware('permission:time.holiday_calendars.view');
    Route::post('/holiday-calendars', [HolidayCalendarController::class, 'store'])->middleware('permission:time.holiday_calendars.create');
    Route::get('/holiday-calendars/{id}', [HolidayCalendarController::class, 'show'])->middleware('permission:time.holiday_calendars.view');
    Route::patch('/holiday-calendars/{id}', [HolidayCalendarController::class, 'update'])->middleware('permission:time.holiday_calendars.update');

    Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->middleware('permission:settings.exchange_rates.view');
    Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])->middleware('permission:settings.exchange_rates.create');
    Route::get('/exchange-rates/convert', [ExchangeRateController::class, 'convert'])->middleware('permission:settings.exchange_rates.view');

    // CFG-10 · Catálogos: leerlos basta con la sesión (los usan los formularios); editarlos exige permiso.
    Route::get('/catalogs', [CatalogController::class, 'index']);
    Route::post('/catalogs', [CatalogController::class, 'store'])->middleware('permission:settings.catalogs.update');
    Route::get('/catalogs/{code}/items', [CatalogController::class, 'items']);
    Route::post('/catalogs/{code}/items', [CatalogController::class, 'storeItem'])->middleware('permission:settings.catalogs.update');
    Route::patch('/catalog-items/{id}', [CatalogController::class, 'updateItem'])->middleware('permission:settings.catalogs.update');
    Route::post('/catalog-items/{id}/archive', [CatalogController::class, 'archive'])->middleware('permission:settings.catalogs.update');
    Route::post('/catalog-items/{id}/reactivate', [CatalogController::class, 'reactivate'])->middleware('permission:settings.catalogs.update');

    Route::get('/custom-fields', [CustomFieldController::class, 'index'])->middleware('permission:settings.custom_fields.view');
    Route::post('/custom-fields', [CustomFieldController::class, 'store'])->middleware('permission:settings.custom_fields.create');
    Route::get('/custom-fields/form', [CustomFieldController::class, 'form']);
    Route::get('/custom-fields/{id}', [CustomFieldController::class, 'show'])->middleware('permission:settings.custom_fields.view');
    Route::patch('/custom-fields/{id}', [CustomFieldController::class, 'update'])->middleware('permission:settings.custom_fields.update');
    Route::delete('/custom-fields/{id}', [CustomFieldController::class, 'destroy'])->middleware('permission:settings.custom_fields.delete');
});
