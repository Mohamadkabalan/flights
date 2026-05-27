<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureApiKeyIsValid.
 *
 * Guards every API endpoint with a required `Api-Key` header. The expected
 * secret lives in configuration (config/auth-api.php -> env), never in code.
 *
 * Status semantics:
 *   - 401 Unauthorized : no Api-Key header was provided (you are not identified).
 *   - 403 Forbidden    : an Api-Key was provided but does not match (identified,
 *                        but not allowed).
 *
 * This separation lets clients distinguish "I forgot the header" from "my key is
 * wrong", which is the conventional and most debuggable behaviour.
 */
final class EnsureApiKeyIsValid
{
    /**
     * The header clients must send.
     */
    public const HEADER = 'Api-Key';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $providedKey = $request->header(self::HEADER);

        // 401: the header is entirely absent or blank.
        if ($providedKey === null || $providedKey === '') {
            return $this->unauthorized();
        }

        /** @var string|null $expectedKey */
        $expectedKey = config('auth-api.key');

        // Misconfiguration guard: if no server-side key is configured, fail
        // closed (treat as forbidden) rather than accidentally allowing access.
        if (! is_string($expectedKey) || $expectedKey === '') {
            return $this->forbidden();
        }

        // 403: a key was supplied but does not match. hash_equals performs a
        // constant-time comparison to avoid leaking information via timing.
        if (! hash_equals($expectedKey, $providedKey)) {
            return $this->forbidden();
        }

        // Valid key — proceed to the route.
        return $next($request);
    }

    /**
     * Build a 401 JSON response for a missing key.
     */
    private function unauthorized(): Response
    {
        return response()->json(
            ['message' => 'Missing ' . self::HEADER . ' header.'],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    /**
     * Build a 403 JSON response for an invalid key.
     */
    private function forbidden(): Response
    {
        return response()->json(
            ['message' => 'Invalid ' . self::HEADER . '.'],
            Response::HTTP_FORBIDDEN,
        );
    }
}
