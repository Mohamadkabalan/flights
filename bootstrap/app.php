<?php

declare(strict_types=1);

use App\Exceptions\IdempotencyConflictException;
use App\Http\Middleware\EnsureApiKeyIsValid;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/**
 * Application bootstrap (Laravel 12).
 *
 * In Laravel 12 there is no Http/Kernel.php or Exceptions/Handler.php — routing,
 * middleware, and exception handling are all configured here.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Register our API routes. We point `api` at routes/api.php; the routes
        // file itself applies the `api` prefix via its group, so we do NOT pass
        // an additional apiPrefix here (that would double-prefix to /api/api).
        api: __DIR__ . '/../routes/api.php',
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        // A lightweight health-check endpoint at /up, handy for container
        // orchestration readiness probes.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register an alias so routes can reference the Api-Key guard by a short
        // name if desired. The routes file references the class directly, but the
        // alias is convenient and self-documenting.
        $middleware->alias([
            'api-key' => EnsureApiKeyIsValid::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always render exceptions as JSON for API routes, even if the client
        // forgot an Accept header — this is an API-only surface.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, \Throwable $e): bool =>
                $request->is('api/*') || $request->expectsJson(),
        );

        // Map the idempotency conflict (same key, different body) to a clean 422
        // JSON response. We read the status off the exception so the mapping
        // stays in one place (the exception class owns its status).
        $exceptions->render(function (IdempotencyConflictException $e, Request $request) {
            return response()->json(
                ['message' => $e->getMessage()],
                $e->status,
            );
        });
    })
    ->create();
