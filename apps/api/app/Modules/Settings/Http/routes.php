<?php

use App\Modules\Settings\Http\Controllers\CalendarController;
use App\Modules\Settings\Http\Controllers\CatalogController;
use App\Modules\Settings\Http\Controllers\CustomFieldController;
use App\Modules\Settings\Http\Controllers\ExchangeRateController;
use App\Modules\Settings\Http\Controllers\HolidayCalendarController;
use App\Modules\Settings\Http\Controllers\SavedViewController;
use App\Modules\Settings\Http\Controllers\SettingsController;
use App\Modules\Settings\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->middleware(['api', 'auth:ayni'])->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'groups'])->middleware('permission:settings.parameters.view');
    Route::get('/settings/{group}', [SettingsController::class, 'show'])->middleware('permission:settings.parameters.view');
    Route::put('/settings/{group}', [SettingsController::class, 'update'])->middleware('permission:settings.parameters.update');

    // SAS-6 · Consumo frente a los límites del plan (lo ve quien ve los datos de la organización).
    Route::get('/usage', [UsageController::class, 'index'])->middleware('permission:settings.organization.view');

    Route::get('/holiday-calendars', [HolidayCalendarController::class, 'index'])->middleware('permission:time.holiday_calendars.view');
    Route::post('/holiday-calendars', [HolidayCalendarController::class, 'store'])->middleware('permission:time.holiday_calendars.create');
    Route::get('/holiday-calendars/{id}', [HolidayCalendarController::class, 'show'])->middleware('permission:time.holiday_calendars.view');
    Route::patch('/holiday-calendars/{id}', [HolidayCalendarController::class, 'update'])->middleware('permission:time.holiday_calendars.update');

    // CFG-8 · Calendario central de RRHH (fuentes de los módulos contratados, con el alcance de cada una).
    Route::get('/calendar', [CalendarController::class, 'index'])->middleware('permission:time.calendar.view');

    Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->middleware('permission:settings.exchange_rates.view');
    Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])->middleware('permission:settings.exchange_rates.create');
    Route::get('/exchange-rates/convert', [ExchangeRateController::class, 'convert'])->middleware('permission:settings.exchange_rates.view');
    Route::post('/exchange-rates/import', [ExchangeRateController::class, 'import'])->middleware('permission:settings.exchange_rates.create');

    // CFG-10 · Catálogos: leerlos basta con la sesión (los usan los formularios); editarlos exige permiso.
    Route::get('/catalogs', [CatalogController::class, 'index']);
    Route::post('/catalogs', [CatalogController::class, 'store'])->middleware('permission:settings.catalogs.update');
    Route::get('/catalogs/{code}/items', [CatalogController::class, 'items']);
    Route::post('/catalogs/{code}/items', [CatalogController::class, 'storeItem'])->middleware('permission:settings.catalogs.update');
    Route::patch('/catalog-items/{id}', [CatalogController::class, 'updateItem'])->middleware('permission:settings.catalogs.update');
    Route::post('/catalog-items/{id}/archive', [CatalogController::class, 'archive'])->middleware('permission:settings.catalogs.update');
    Route::post('/catalog-items/{id}/reactivate', [CatalogController::class, 'reactivate'])->middleware('permission:settings.catalogs.update');

    // UI-4 · Vistas guardadas de las tablas: cada usuario sobre las suyas; las compartidas solo se leen.
    Route::get('/saved-views', [SavedViewController::class, 'index'])->middleware('permission:settings.saved_views.view');
    Route::post('/saved-views', [SavedViewController::class, 'store'])->middleware('permission:settings.saved_views.create');
    Route::get('/saved-views/{id}', [SavedViewController::class, 'show'])->middleware('permission:settings.saved_views.view');
    Route::patch('/saved-views/{id}', [SavedViewController::class, 'update'])->middleware('permission:settings.saved_views.update');
    Route::delete('/saved-views/{id}', [SavedViewController::class, 'destroy'])->middleware('permission:settings.saved_views.delete');

    Route::get('/custom-fields', [CustomFieldController::class, 'index'])->middleware('permission:settings.custom_fields.view');
    Route::post('/custom-fields', [CustomFieldController::class, 'store'])->middleware('permission:settings.custom_fields.create');
    Route::get('/custom-fields/form', [CustomFieldController::class, 'form']);
    Route::get('/custom-fields/{id}', [CustomFieldController::class, 'show'])->middleware('permission:settings.custom_fields.view');
    Route::patch('/custom-fields/{id}', [CustomFieldController::class, 'update'])->middleware('permission:settings.custom_fields.update');
    Route::delete('/custom-fields/{id}', [CustomFieldController::class, 'destroy'])->middleware('permission:settings.custom_fields.delete');
});
