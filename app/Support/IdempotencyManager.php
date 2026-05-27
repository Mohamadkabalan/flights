<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * IdempotencyManager.
 *
 * The single, reusable home for idempotency logic. Nothing else in the app needs
 * to know HOW idempotency is enforced — callers just ask this manager to
 * "register" a key for an operation and act on the returned outcome.
 *
 * Concurrency strategy (the important part):
 * ------------------------------------------
 * We do NOT do "SELECT then INSERT" — that has a time-of-check/time-of-use race
 * where two concurrent requests both see "no row" and both insert. Instead we
 * attempt an ATOMIC INSERT and let the database's UNIQUE(key, operation)
 * constraint arbitrate the winner:
 *
 *   - If the insert succeeds  -> we are the first; outcome = ACCEPTED.
 *   - If it fails on the unique violation -> someone already registered this
 *     key. We then load the existing row and compare request hashes:
 *        * same hash      -> legitimate retry/duplicate -> DUPLICATE (204)
 *        * different hash -> contract violation         -> CONFLICT (422)
 *
 * This makes duplicate suppression correct even under simultaneous submissions,
 * relying on a database guarantee rather than application-level timing.
 */
final class IdempotencyManager
{
    /**
     * Register an idempotency key for an operation.
     *
     * @param  string       $key          The client-supplied Idempotency-Key.
     * @param  string       $operation    Logical operation, e.g. "update_flight".
     * @param  string       $requestHash  Canonical hash of the request payload.
     * @param  string|null  $flightId     The target flight UUID (for traceability).
     * @return array{0: IdempotentRegistration, 1: IdempotencyKey}
     *         The outcome plus the authoritative idempotency row.
     */
    public function register(
        string $key,
        string $operation,
        string $requestHash,
        ?string $flightId = null,
    ): array {
        try {
            // Attempt the atomic insert. If this key+operation is new, we win the
            // race and own the work. status starts as PENDING.
            $record = IdempotencyKey::create([
                'key' => $key,
                'operation' => $operation,
                'flight_id' => $flightId,
                'request_hash' => $requestHash,
                'status' => IdempotencyKey::STATUS_PENDING,
            ]);

            return [IdempotentRegistration::ACCEPTED, $record];
        } catch (QueryException $e) {
            // A unique-constraint violation means a row for (key, operation)
            // already exists. Any other DB error is unexpected and re-thrown.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Load the existing authoritative row to decide duplicate vs conflict.
            /** @var IdempotencyKey $existing */
            $existing = IdempotencyKey::query()
                ->forKey($key, $operation)
                ->firstOrFail();

            // Same body under the same key => legitimate retry. Same observable
            // result as the first call (no new job dispatched).
            if (hash_equals((string) $existing->request_hash, $requestHash)) {
                return [IdempotentRegistration::DUPLICATE, $existing];
            }

            // Different body under the same key => contract violation.
            return [IdempotentRegistration::CONFLICT, $existing];
        }
    }

    /**
     * Detect whether a QueryException is a unique-constraint violation, across
     * the relational drivers this app supports.
     *
     * - MySQL/MariaDB: SQLSTATE 23000 with driver error 1062.
     * - PostgreSQL:    SQLSTATE 23505.
     * - SQLite:        SQLSTATE 23000 (used in tests).
     *
     * We inspect the SQLSTATE (errorInfo[0]) which is portable, and fall back to
     * a message check for SQLite's phrasing.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        // PostgreSQL unique violation.
        if ($sqlState === '23505') {
            return true;
        }

        // MySQL/SQLite share SQLSTATE 23000 for integrity violations; narrow it
        // to a UNIQUE violation specifically.
        if ($sqlState === '23000') {
            $driverCode = $e->errorInfo[1] ?? null;
            $message = $e->getMessage();

            // MySQL duplicate-entry driver code, or SQLite's textual marker.
            return $driverCode === 1062
                || str_contains($message, 'UNIQUE constraint failed')
                || str_contains($message, 'Duplicate entry');
        }

        return false;
    }

    // =====================================================================
    // Job-side lifecycle.
    //
    // The methods above handle REGISTRATION (the API request path). The methods
    // below handle EXECUTION (the queued job path): acquiring a short-lived
    // Redis lock so only one worker runs a given key at a time, claiming the DB
    // row with a lease, and finalizing the record. Keeping both halves in this
    // one class is what makes the idempotency logic "isolated and reusable".
    // =====================================================================

    /**
     * The Redis lock TTL (seconds). A worker holds this lock only for the brief
     * critical section around claiming+running; the durable guarantee is the DB
     * record, so this is just belt-and-suspenders against two workers racing on
     * the same key simultaneously.
     */
    private const LOCK_TTL = 90;

    /**
     * How long to wait to acquire the lock before giving up (seconds).
     */
    private const LOCK_WAIT = 10;

    /**
     * The DB-level processing lease (seconds). If a worker dies mid-job, another
     * worker may reclaim the row once `locked_until` is in the past.
     */
    private const LEASE_SECONDS = 120;

    /**
     * Run the given callback while holding a Redis lock scoped to this key.
     *
     * Uses Laravel's atomic cache lock (backed by Redis in this app). If the lock
     * cannot be obtained within LOCK_WAIT seconds, a LockTimeoutException is
     * thrown and the job may retry later — another worker is already handling
     * this key, so backing off is correct.
     *
     * @template T
     * @param  Closure():T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    public function withRedisLock(string $key, string $operation, Closure $callback): mixed
    {
        // Lock name namespaced by operation+key so unrelated keys never contend.
        // TTL deliberately exceeds the job timeout (see LOCK_TTL constant).
        $lock = Cache::lock("idempotency:{$operation}:{$key}", self::LOCK_TTL);

        // block() waits up to LOCK_WAIT seconds, then auto-releases after the
        // callback (or on the TTL) so a crash cannot strand the lock forever.
        return $lock->block(self::LOCK_WAIT, $callback);
    }

    /**
     * Atomically claim a PENDING/reclaimable record for processing.
     *
     * Inside a transaction we lock the row (FOR UPDATE), then only transition it
     * to PROCESSING if it is still claimable: either PENDING, or a PROCESSING row
     * whose lease has expired (a dead worker). Returns true if THIS caller won
     * the claim, false if the work is already done or actively owned elsewhere.
     *
     * This double layer (Redis lock + DB row lock + status check) means even if
     * the Redis lock were somehow bypassed, the DB still admits exactly one
     * worker to the critical transition.
     */
    public function claim(int $idempotencyKeyId): bool
    {
        /** @var IdempotencyKey|null $record */
        $record = IdempotencyKey::query()
            ->where('id', $idempotencyKeyId)
            ->lockForUpdate()
            ->first();

        if ($record === null) {
            return false;
        }

        // Already finished — nothing to do (idempotent replay).
        if ($record->isCompleted()) {
            return false;
        }

        // Actively owned by a live worker (processing + unexpired lease).
        if ($record->isActivelyLocked()) {
            return false;
        }

        // Claimable: mark processing and set a fresh lease.
        $record->update([
            'status' => IdempotencyKey::STATUS_PROCESSING,
            'locked_until' => Carbon::now()->addSeconds(self::LEASE_SECONDS),
        ]);

        return true;
    }

    /**
     * Mark a record as successfully completed, recording the response code and
     * clearing the lease. Idempotent: safe to call once the work has run.
     */
    public function markCompleted(int $idempotencyKeyId, int $responseCode): void
    {
        IdempotencyKey::query()
            ->where('id', $idempotencyKeyId)
            ->update([
                'status' => IdempotencyKey::STATUS_COMPLETED,
                'response_code' => $responseCode,
                'processed_at' => Carbon::now(),
                'locked_until' => null,
            ]);
    }

    /**
     * Mark a record as failed and clear the lease so the failure is visible and
     * the row is not left dangling in PROCESSING.
     */
    public function markFailed(int $idempotencyKeyId): void
    {
        IdempotencyKey::query()
            ->where('id', $idempotencyKeyId)
            ->update([
                'status' => IdempotencyKey::STATUS_FAILED,
                'locked_until' => null,
            ]);
    }
}
