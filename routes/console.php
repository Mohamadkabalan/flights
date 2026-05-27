<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune old idempotency records daily so the table does not grow unbounded.
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])
  ->daily();