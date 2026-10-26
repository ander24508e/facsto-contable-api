<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// F06: retoma los comprobantes que esperan respuesta del SRI (autorización en proceso, Jobs perdidos).
Schedule::command('sri:dispatch-pending')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
