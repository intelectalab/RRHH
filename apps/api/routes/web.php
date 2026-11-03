<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['api' => 'Ayni HR', 'version' => '1.0.0']);
});
