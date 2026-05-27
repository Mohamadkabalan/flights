<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Jobs\UpdateFlightJob;
use App\Models\Flight;
use App\Models\IdempotencyKey;
use App\Support\IdempotencyManager;
use App\Support\RequestHasher;

/**
 * FlightUpdateDispatcher.
 *
 * Coordinates the SYNCHRONOUS part of the update flow. It deliberately does NOT
 * touch flight/leg/segment tables — the actual mutation happens later in the
 * queued UpdateFlightJob. This service's responsibilities are:
 *
 *   1. Compute a canonical hash of the incoming payload.
 *   2. Register the Idempotency-Key atomically (delegated to IdempotencyManager).
 *   3. Translate the registration outcome into the right behaviour:
 *        - ACCEPTED  -> dispatch the queued update job.
 *        - DUPLICATE -> do nothing (the first request already owns the work).
 *        - CONFLICT  -> throw, so the API returns 422.
 *
 * Keeping this logic here keeps the controller thin and the job focused purely
 * on persistence.
 */
final class FlightUpdateDispatcher
{
    public function __construct(
        private readonly IdempotencyManager $idempotency,
    ) {
    }

    /**
     * Register and (if new) enqueue an asynchronous flight update.
     *
     * @param  Flight                              $flight          Target flight (resolved by UUID).
     * @param  array<int, array<string, mixed>>    $legs            Validated legs payload.
     * @param  string                              $idempotencyKey  Validated Idempotency-Key header.
     *
     * @throws IdempotencyConflictException  If the key was used with a different body.
     */
    public function dispatch(Flight $flight, array $legs, string $idempotencyKey): void
    {
        // Hash the payload we are guarding. Including the flight UUID in the
        // hashed structure ties the key to BOTH the body and its target, so the
        // same key cannot be reused across different flights with the same body.
        $requestHash = RequestHasher::hash([
            'flightId' => $flight->uuid,
            'legs' => $legs,
        ]);

        // Atomically register the key. The manager uses the DB unique constraint
        // to make this race-safe under concurrent submissions.
        [$outcome, $record] = $this->idempotency->register(
            key: $idempotencyKey,
            operation: IdempotencyKey::OPERATION_UPDATE_FLIGHT,
            requestHash: $requestHash,
            flightId: $flight->uuid,
        );

        // Same key, different body: reject. This is the only error path; both
        // ACCEPTED and DUPLICATE result in 204 for the client.
        if ($outcome->isConflict()) {
            throw IdempotencyConflictException::forKey($idempotencyKey);
        }

        // Duplicate/retry: the original request already dispatched (or will
        // dispatch) the job. Doing nothing here is what prevents duplicate
        // updates from retries, timeouts, or concurrent submissions.
        if (! $outcome->shouldDispatch()) {
            return;
        }

        // First time we have seen this key: enqueue the real work. The job
        // receives the flight UUID, the legs payload, and the idempotency key so
        // it can claim/lock the record and run the update transactionally.
        UpdateFlightJob::dispatch(
            flightUuid: $flight->uuid,
            legs: $legs,
            idempotencyKeyId: $record->id,
        );
    }
}
