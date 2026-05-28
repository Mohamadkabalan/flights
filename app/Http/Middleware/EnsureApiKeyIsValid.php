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
    public const string HEADER = 'Api-Key';

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
            return $this->deny(Response::HTTP_UNAUTHORIZED, 'Missing ' . self::HEADER . ' header.');
        }

        /** @var string|null $expectedKey */
        $expectedKey = config('auth-api.key');

        // 403: either the server has no key configured (fail closed) or the
        // supplied key does not match. hash_equals is constant-time to avoid
        // leaking information via timing. The is_string guard also ensures we
        // never pass a non-string to hash_equals.
        $valid = is_string($expectedKey)
          && $expectedKey !== ''
          && hash_equals($expectedKey, $providedKey);

        if (! $valid) {
            return $this->deny(Response::HTTP_FORBIDDEN, 'Invalid ' . self::HEADER . '.');
        }

        // Valid key — proceed to the route.
        return $next($request);
    }
    /**
     * Build a 401 JSON response for a missing key.
     */
    /**
     * Build a JSON denial response with the given status and message.
     */
    private function deny(int $status, string $message): Response
    {
        return response()->json(['message' => $message], $status);
    }

}
