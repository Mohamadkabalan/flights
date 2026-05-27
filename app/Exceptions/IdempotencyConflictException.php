<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when a client reuses an existing Idempotency-Key with a DIFFERENT
 * request body.
 *
 * Per the idempotency contract, a given key must always represent the same
 * request. Sending a different body under the same key is a client mistake and
 * is rejected with HTTP 422 (Unprocessable Entity).
 *
 * The status property is read by Laravel's exception renderer (any exception
 * exposing getStatusCode()-style intent) — here we surface it explicitly so the
 * controller layer or the framework's handler can produce the right response.
 */
final class IdempotencyConflictException extends RuntimeException
{
    /**
     * The HTTP status this exception maps to. 422 communicates "the request was
     * well-formed but conflicts with a previously-seen request for this key".
     */
    public int $status = Response::HTTP_UNPROCESSABLE_ENTITY;

    /**
     * Convenience factory with a clear, client-facing message.
     */
    public static function forKey(string $key): self
    {
        return new self(
            "The Idempotency-Key '{$key}' was already used with a different request payload.",
        );
    }
}
