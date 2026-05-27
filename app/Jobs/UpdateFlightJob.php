<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IdempotencyKey;
use App\Repositories\FlightRepository;
use App\Support\IdempotencyManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * UpdateFlightJob.
 *
 * The asynchronous worker that performs the ACTUAL flight update. The API
 * endpoint only validated, registered the idempotency key, and dispatched this
 * job; ALL database mutation happens here, inside a transaction.
 *
 * Safety layering (why this is robust under retries/concurrency):
 *   1. Redis lock (per idempotency key) — admits one worker at a time.
 *   2. DB row claim with lease — the durable "process once" guarantee; survives
 *      a Redis flush and lets a dead worker's lease be reclaimed.
 *   3. DB transaction with a pessimistic lock on the flight row — serializes
 *      structural writes to the same flight and gives all-or-nothing semantics.
 */
final class UpdateFlightJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of times the job may be attempted before being marked failed.
     * Retries cover transient issues (deadlocks, lock wait timeouts); the
     * idempotency claim ensures a retry never double-applies the update.
     */
    public int $tries = 3;

    /**
     * Seconds to wait between retries (backoff). Gives a contending worker or a
     * brief lock time to clear.
     */
    public int $backoff = 5;

    /**
     * @param  string                              $flightUuid        Target flight UUID.
     * @param  array<int, array<string, mixed>>    $legs              Validated legs payload.
     * @param  int                                 $idempotencyKeyId  PK of the idempotency record.
     */
    public function __construct(
        public readonly string $flightUuid,
        public readonly array $legs,
        public readonly int $idempotencyKeyId,
    ) {
    }

    /**
     * Execute the job.
     *
     * Dependencies are resolved from the container (method injection), keeping
     * the job free of construction concerns and easy to test with fakes.
     */
    public function handle(
        IdempotencyManager $idempotency,
        FlightRepository $flights,
    ): void {
        // Acquire a short-lived Redis lock scoped to this idempotency key so two
        // workers cannot enter the critical section simultaneously. If the lock
        // cannot be obtained, another worker holds it — release this attempt back
        // to the queue to retry shortly.
        try {
            $idempotency->withRedisLock(
                $this->idempotencyKeyOrThrow()->key,
                IdempotencyKey::OPERATION_UPDATE_FLIGHT,
                fn () => $this->process($idempotency, $flights),
            );
        } catch (LockTimeoutException $e) {
            // Could not get the lock in time; let the queue retry later.
            $this->release($this->backoff);
        }
    }

    /**
     * The locked critical section: claim the record, run the update, finalize.
     */
    private function process(IdempotencyManager $idempotency, FlightRepository $flights): void
    {
        // Atomically claim the record. If we don't win the claim, the work is
        // already done or actively owned — return without doing anything. This is
        // the core "process the same key only once" guarantee.
        if (! $idempotency->claim($this->idempotencyKeyId)) {
            return;
        }

        try {
            // Perform the real update transactionally. lockForUpdate inside the
            // transaction serializes concurrent updates to the same flight.
            DB::transaction(function () use ($flights): void {
                $flight = $flights->findForUpdate($this->flightUuid);

                // Flight could have been deleted between request and processing.
                // Without a flight there is nothing to update; we treat this as a
                // no-op success so the key is not stuck retrying forever.
                if ($flight === null) {
                    return;
                }

                $flights->applyPositionalUpdate($flight, $this->legs);
            });

            // Record success. 204 mirrors the synchronous response the API gave.
            $idempotency->markCompleted(
                $this->idempotencyKeyId,
                Response::HTTP_NO_CONTENT,
            );
        } catch (Throwable $e) {
            // The transaction already rolled back any partial writes. Mark the
            // key failed (clearing its lease) and rethrow so the queue's retry/
            // failure handling applies.
            $idempotency->markFailed($this->idempotencyKeyId);

            throw $e;
        }
    }

    /**
     * Load the idempotency record or throw — used to obtain the key string for
     * the Redis lock. If the record is missing something is badly wrong, so we
     * fail loudly rather than silently skip.
     */
    private function idempotencyKeyOrThrow(): IdempotencyKey
    {
        return IdempotencyKey::query()->findOrFail($this->idempotencyKeyId);
    }

    /**
     * Final failure hook: invoked after all retries are exhausted. Ensures the
     * idempotency record is not left dangling in PROCESSING so operators can see
     * the terminal failure and clients are not blocked by a stuck lease.
     */
    public function failed(Throwable $exception): void
    {
        app(IdempotencyManager::class)->markFailed($this->idempotencyKeyId);
    }
}
