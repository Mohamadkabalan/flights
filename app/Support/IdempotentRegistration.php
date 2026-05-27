<?php

declare(strict_types=1);

namespace App\Support;

/**
 * IdempotentRegistration.
 *
 * Describes what happened when we tried to register an Idempotency-Key for an
 * update. The dispatcher returns one of these so callers (controller, tests)
 * can reason about the outcome explicitly instead of guessing from booleans.
 *
 * Note: from the HTTP client's perspective ACCEPTED and DUPLICATE both result
 * in 204 — the whole point of idempotency is that a retried request looks the
 * same as the first. CONFLICT is the one error case (same key, different body).
 */
enum IdempotentRegistration: string
{
    /**
     * A brand-new key was registered and a job was dispatched. This is the first
     * time we have seen this key.
     */
    case ACCEPTED = 'accepted';

    /**
     * The key already exists with the SAME request body. This is a duplicate /
     * retry. We do NOT dispatch a second job; the original is authoritative.
     * The client still receives 204 — identical observable behaviour.
     */
    case DUPLICATE = 'duplicate';

    /**
     * The key already exists but with a DIFFERENT request body. This violates
     * the idempotency contract and must be surfaced as an error (HTTP 422).
     */
    case CONFLICT = 'conflict';

    /**
     * Whether this outcome should dispatch (or have dispatched) a job. Only a
     * freshly accepted registration triggers new work.
     */
    public function shouldDispatch(): bool
    {
        return $this === self::ACCEPTED;
    }

    /**
     * Whether this outcome represents the idempotency-contract violation that
     * must be rejected.
     */
    public function isConflict(): bool
    {
        return $this === self::CONFLICT;
    }
}
