<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// SUNAT solo publica la tasa del día y no expone histórico, así que hay que
// capturarla a diario para ir acumulando las fechas. Dos corridas por si la
// primera falla; la tasa del día no cambia, así que repetir es inocuo.
Schedule::command('sunat:sync-tipo-cambio')->twiceDaily(6, 18)->withoutOverlapping();
