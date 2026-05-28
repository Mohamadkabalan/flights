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
 * register a key for an operation and act on the returned outcome.
 *
 * Concurrency strategy:
 * ---------------------
 * We do NOT do "SELECT then INSERT" — that has a time-of-check/time-of-use race
 * where two concurrent requests both see "no row" and both insert. Instead we
 * attempt an ATOMIC INSERT and let the database's UNIQUE(key, operation)
 * constraint arbitrate the winner.
 *
 * Two registration APIs exist:
 *
 *   - register(): the original one-shot call. It catches the unique violation
 *     and resolves duplicate-vs-conflict in the same method. This is convenient
 *     OUTSIDE a transaction but is NOT Postgres-safe inside one, because on
 *     Postgres a caught error aborts the surrounding transaction and the
 *     follow-up SELECT would fail.
 *
 *   - tryInsert() + resolveExisting(): the split form used by the dispatcher so
 *     registration and the queue dispatch can live in ONE transaction with the
 *     job pushed via DB::afterCommit. tryInsert only ever performs the insert;
 *     the caller lets a "row already exists" result fall OUT of the transaction
 *     and then calls resolveExisting() in a clean query. This avoids querying a
 *     poisoned transaction.
 */
final class IdempotencyManager
{
    /**
     * Register an idempotency key for an operation (one-shot form).
     *
     * Convenient when NOT operating inside a transaction. Inside a transaction,
     * prefer tryInsert()/resolveExisting() — see the class docblock.
     *
     * @param  string       $key          The client-supplied Idempotency-Key.
     * @param  string       $operation    Logical operation, e.g. "update_flight".
     * @param  string       $requestHash  Canonical hash of the request payload.
     * @param  string|null  $flightId     The target flight UUID (for traceability).
     * @return array{0: IdempotentRegistration, 1: IdempotencyKey}
     */
    public function register(
      string $key,
      string $operation,
      string $requestHash,
      ?string $flightId = null,
    ): array {
        try {
            $record = IdempotencyKey::create([
              'key' => $key,
              'operation' => $operation,
              'flight_id' => $flightId,
              'request_hash' => $requestHash,
              'status' => IdempotencyKey::STATUS_PENDING,
            ]);

            return [IdempotentRegistration::ACCEPTED, $record];
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return $this->resolveExisting($key, $operation, $requestHash);
        }
    }

    /**
     * Attempt ONLY the atomic insert.
     *
     * Returns the freshly-created record on success, or null if a row for
     * (key, operation) already exists. Any non-unique DB error rethrows.
     *
     * Designed to be called inside a transaction: a null return tells the caller
     * "the key already existed" without performing any follow-up query that a
     * Postgres-aborted transaction would reject. The caller resolves the
     * duplicate/conflict with resolveExisting() AFTER the transaction.
     *
     * @param  array<string, mixed>|null  $payload  The request body to persist for
     *         recovery/re-dispatch. Stored verbatim; nulled out on completion.
     */
    public function tryInsert(
      string $key,
      string $operation,
      string $requestHash,
      ?string $flightId = null,
      ?array $payload = null,
    ): ?IdempotencyKey {
        try {
            return IdempotencyKey::create([
              'key' => $key,
              'operation' => $operation,
              'flight_id' => $flightId,
              'request_hash' => $requestHash,
              'request_payload' => $payload,
              'status' => IdempotencyKey::STATUS_PENDING,
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Resolve an already-existing (key, operation) into a duplicate-vs-conflict
     * outcome. MUST be called outside any transaction that a failed insert may
     * have aborted.
     *
     * @return array{0: IdempotentRegistration, 1: IdempotencyKey}
     */
    public function resolveExisting(string $key, string $operation, string $requestHash): array
    {
        /** @var IdempotencyKey $existing */
        $existing = IdempotencyKey::query()
          ->forKey($key, $operation)
          ->firstOrFail();

        // Same body under the same key => legitimate retry.
        if (hash_equals((string) $existing->request_hash, $requestHash)) {
            return [IdempotentRegistration::DUPLICATE, $existing];
        }

        // Different body under the same key => contract violation.
        return [IdempotentRegistration::CONFLICT, $existing];
    }

    /**
     * Detect whether a QueryException is a unique-constraint violation, across
     * the relational drivers this app supports.
     *
     * - MySQL/MariaDB: SQLSTATE 23000 with driver error 1062.
     * - PostgreSQL:    SQLSTATE 23505.
     * - SQLite:        SQLSTATE 23000 (used in tests).
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

            return $driverCode === 1062
              || str_contains($message, 'UNIQUE constraint failed')
              || str_contains($message, 'Duplicate entry');
        }

        return false;
    }

    // =====================================================================
    // Job-side lifecycle.
    // =====================================================================

    /**
     * The Redis lock TTL (seconds). Must exceed the job's configured timeout so
     * the lock cannot auto-release while a worker is still inside the critical
     * section. See UpdateFlightJob::$timeout.
     */
    private const LOCK_TTL = 90;

    /**
     * How long to wait to acquire the lock before giving up (seconds).
     */
    private const LOCK_WAIT = 10;

    /**
     * The DB-level processing lease (seconds). If a worker dies mid-job, another
     * worker may reclaim the row once `locked_until` is in the past. Set higher
     * than LOCK_TTL so the durable lease outlives the in-memory lock.
     */
    private const LEASE_SECONDS = 120;

    /**
     * Run the given callback while holding a Redis lock scoped to this key.
     *
     * @template T
     * @param  Closure():T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    public function withRedisLock(string $key, string $operation, Closure $callback): mixed
    {
        $lock = Cache::lock("idempotency:{$operation}:{$key}", self::LOCK_TTL);

        return $lock->block(self::LOCK_WAIT, $callback);
    }

    /**
     * Atomically claim a PENDING/reclaimable record for processing.
     *
     * Inside a transaction we lock the row (FOR UPDATE), then only transition it
     * to PROCESSING if it is still claimable: either PENDING, or a PROCESSING row
     * whose lease has expired. Returns true if THIS caller won the claim.
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

        if ($record->isCompleted()) {
            return false;
        }

        if ($record->isActivelyLocked()) {
            return false;
        }

        $record->update([
          'status' => IdempotencyKey::STATUS_PROCESSING,
          'locked_until' => Carbon::now()->addSeconds(self::LEASE_SECONDS),
        ]);

        return true;
    }

    /**
     * Mark a record as successfully completed, recording the response code and
     * clearing the lease. Also nulls the stored payload — once completed there
     * is nothing to re-dispatch, so we keep the table lean.
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
            'request_payload' => null,
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