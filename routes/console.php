<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Rafraîchissement mensuel du référentiel BDPM (1er du mois à 3h00)
Schedule::command('pilo:import-bdpm')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->onFailure(fn () => logger()->error('pilo:import-bdpm a échoué'));
