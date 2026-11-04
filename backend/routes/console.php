<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('products:reactivate')->everyMinute();

// Asistencia: las fotos de las marcas se borran a los 90 días; la marca queda (docs/PLAN_ASISTENCIA_Y_SUELDOS.md, D9)
Artisan::command('attendance:purge-photos', function (\App\Services\Attendance\AttendanceService $attendance) {
    $this->info('Fotos de marcas borradas: ' . $attendance->purgeOldPhotos());
})->purpose('Borra las fotos de las marcas de asistencia más viejas que el plazo de guarda');

Schedule::command('attendance:purge-photos')->dailyAt('03:15');
