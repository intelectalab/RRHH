<?php

use Illuminate\Support\Facades\Schedule;

// Ejemplo de tarea programada; ampliar en cada módulo.
Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();
