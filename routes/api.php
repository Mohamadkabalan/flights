<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FlightController;
use App\Http\Middleware\EnsureApiKeyIsValid;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Exactly the three endpoints the spec permits — nothing more. Every route is
| guarded by the Api-Key middleware. We apply the middleware via a group so the
| protection is declared once and cannot be forgotten on an individual route.
|
| Route-model binding resolves the {flight} wildcard against the Flight model's
| `uuid` column (see Flight::getRouteKeyName), so a missing UUID yields an
| automatic 404 before the controller runs.
|
*/

Route::middleware([EnsureApiKeyIsValid::class, 'throttle:api'])
    ->group(function (): void {
        // 1. Create a flight (with nested legs + segments). Returns 201 + uuid.
        Route::post('/flights', [FlightController::class, 'store'])
            ->name('flights.store');

        // 2. Update a flight asynchronously. Accepts BOTH PUT and POST per the
        //    spec ("POST or PUT /api/flights/{flightId}"). Returns 204.
        //    The {flight} parameter is constrained to a UUID shape so malformed
        //    identifiers 404 at the routing layer rather than hitting the DB.
        Route::match(['put', 'post'], '/flights/{flight}', [FlightController::class, 'update'])
            ->whereUuid('flight')
            ->name('flights.update');

        // 3. Fetch a flight's legs/segments. Returns 200 + the flight resource.
        Route::get('/flights/{flight}', [FlightController::class, 'show'])
            ->whereUuid('flight')
            ->name('flights.show');
    });
