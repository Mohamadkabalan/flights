<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Recovery net for lost flight-update jobs. Re-dispatches PENDING idempotency
| rows whose job never reached a worker. withoutOverlapping prevents two runs
| from racing if a single invocation runs long.
|
*/
Schedule::command('flights:redispatch-stuck')
  ->everyFiveMinutes()
  ->withoutOverlapping();

// Prune old idempotency rows (the model is Prunable).
Schedule::command('model:prune')->daily();