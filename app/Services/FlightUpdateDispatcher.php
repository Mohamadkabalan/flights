<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Jobs\UpdateFlightJob;
use App\Models\Flight;
use App\Models\IdempotencyKey;
use App\Support\IdempotencyManager;
use App\Support\RequestHasher;
use Illuminate\Support\Facades\DB;

/**
 * FlightUpdateDispatcher.
 *
 * Coordinates the SYNCHRONOUS part of the update flow. It does NOT touch
 * flight/leg/segment tables — the mutation happens later in the queued
 * UpdateFlightJob. Responsibilities:
 *
 *   1. Compute a canonical hash of the incoming payload.
 *   2. Register the Idempotency-Key and enqueue the job ATOMICALLY: both live
 *      inside one transaction, and the job is pushed only via DB::afterCommit so
 *      it is guaranteed to be queued iff the registration row is committed.
 *   3. Translate the registration outcome:
 *        - ACCEPTED  -> row committed + job dispatched on commit.
 *        - DUPLICATE -> do nothing (the first request owns the work).
 *        - CONFLICT  -> throw, so the API returns 422.
 *
 * Why the atomic coupling matters
 * -------------------------------
 * Previously the insert committed and THEN the job was dispatched as two
 * independent steps. A crash between them left a PENDING row with no job: every
 * retry saw DUPLICATE and returned 204, so the client believed an update
 * succeeded that never ran. Tying the dispatch to the commit closes that window;
 * the scheduled `flights:redispatch-stuck` command is the recovery net for the
 * rare case the queue backend itself loses an already-committed push.
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
        //we hash so the idempotency system can tell the difference between:
        //A legitimate retry of the same request (safe)
        //A different request accidentally reusing the same idempotency key (conflict)
        $requestHash = RequestHasher::hash([
          'flightId' => $flight->uuid,
          'legs' => $legs,
        ]);

        // Phase 1: registration + (deferred) dispatch in one transaction.
        // tryInsert only performs the INSERT. If the key already exists it
        // returns null and we resolve duplicate/conflict AFTER the transaction,
        // so we never query a transaction that a unique violation may have
        // aborted (Postgres-safe).
        $record = DB::transaction(function () use ($flight, $legs, $idempotencyKey, $requestHash): ?IdempotencyKey {
            $inserted = $this->idempotency->tryInsert(
              key: $idempotencyKey,
              operation: IdempotencyKey::OPERATION_UPDATE_FLIGHT,
              requestHash: $requestHash,
              flightId: $flight->uuid,
              payload: ['legs' => $legs],
            );

            if ($inserted === null) {
                // Key already existed — fall out and resolve outside the txn.
                return null;
            }

            // Fresh registration: push the job only once this transaction
            // commits. If anything rolls the transaction back, the job is never
            // queued, so we never strand a committed row without a job.
            DB::afterCommit(function () use ($flight, $legs, $inserted): void {
                UpdateFlightJob::dispatch(
                  flightUuid: $flight->uuid,
                  legs: $legs,
                  idempotencyKeyId: $inserted->id,
                );
            });

            return $inserted;
        });

        // First time we saw this key: committed and queued. Done.
        if ($record !== null) {
            return;
        }

        // Phase 2: the key already existed. Resolve duplicate vs conflict in a
        // clean query, now that the (possibly aborted) insert transaction is over.
        [$outcome] = $this->idempotency->resolveExisting(
          $idempotencyKey,
          IdempotencyKey::OPERATION_UPDATE_FLIGHT,
          $requestHash,
        );

        // Same key, different body: the only error path. Both ACCEPTED and
        // DUPLICATE yield 204 for the client.
        if ($outcome->isConflict()) {
            throw IdempotencyConflictException::forKey($idempotencyKey);
        }

        // DUPLICATE/retry: the original request already owns (and dispatched) the
        // work. Doing nothing is what prevents duplicate updates.
    }
}