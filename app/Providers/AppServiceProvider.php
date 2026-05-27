<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\FlightRepositoryInterface;
use App\Repositories\FlightRepository;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * AppServiceProvider.
 *
 * Wires the one abstraction that needs binding: the flight repository interface
 * to its concrete implementation. The services (FlightCreationService,
 * FlightUpdateDispatcher) and the IdempotencyManager are concrete classes with
 * auto-resolvable dependencies, so the container handles them without explicit
 * bindings — we deliberately do NOT bind them to avoid needless indirection.
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register container bindings.
     */
    public function register(): void
    {
        // Bind the interface so anything type-hinting FlightRepositoryInterface
        // (the UpdateFlightJob) receives the concrete FlightRepository. Using
        // bind() rather than singleton() is fine here: the repository is
        // stateless, so a fresh instance per resolution is harmless and avoids
        // accidental shared state.
        $this->app->bind(FlightRepositoryInterface::class, FlightRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Throttle by the Api-Key header (falls back to IP) so each client key
        // gets its own budget rather than sharing one IP bucket.
        RateLimiter::for('api', function (Request $request): Limit {
            $key = (string) $request->header('Api-Key', $request->ip());

            return Limit::perMinute(60)->by($key);
        });
    }
}
