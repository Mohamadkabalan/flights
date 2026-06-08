<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IdempotencyKey;
use App\Contracts\FlightRepositoryInterface;
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
 * Safety layering:
 *   1. Redis lock (per idempotency key) — admits one worker at a time.
 *   2. DB row claim with lease — the durable "process once" guarantee.
 *   3. DB transaction with a pessimistic lock on the flight row — serializes
 *      structural writes and gives all-or-nothing semantics.
 */
final class UpdateFlightJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Number of attempts before the job is marked failed.
     */
    public int $tries = 3;

    /**
     * Seconds to wait between retries (backoff).
     */
    public int $backoff = 5;

    /**
     * Max seconds the job may run. Kept BELOW IdempotencyManager::LOCK_TTL (90s)
     * so the worker is killed before the Redis lock can auto-release underneath
     * it — guaranteeing the lock genuinely outlives the critical section rather
     * than just aspirationally. The DB claim/lease remains the durable guarantee
     * regardless.
     */
    public int $timeout = 60;

    /**
     * @param  string                            $flightUuid        Target flight UUID.
     * @param  array<int, array<string, mixed>>  $legs              Validated leg payload.
     * @param  int                               $idempotencyKeyId  PK of the idempotency record.
     */
    public function __construct(
      public readonly string $flightUuid,
      public readonly array $legs,
      public readonly int $idempotencyKeyId,
    ) {
    }

    /**
     * Execute the job. Dependencies are resolved from the container.
     */
    public function handle(
      IdempotencyManager $idempotency,
      FlightRepositoryInterface $flights,
    ): void {
        try {
            //locking a Redis key , only one worker at a time can enter the callback for that same idempotency key + operation.
            $idempotency->withRedisLock(
              $this->idempotencyKeyOrThrow()->key,
              IdempotencyKey::OPERATION_UPDATE_FLIGHT,
              fn () => $this->process($idempotency, $flights),
            );
        } catch (LockTimeoutException) {
            // Another worker holds the lock; retry later.
            $this->release($this->backoff);
        }
    }

    /**
     * The locked critical section: claim the record, run the update, finalize.
     *
     * @throws \Throwable
     */
    private function process(IdempotencyManager $idempotency, FlightRepositoryInterface $flights): void
    {
        // claim idempotency key, which should be added in the flight update dispatcher. If we don't win the claim, the work is
        // already done or actively owned — return without doing anything.
        if (! $idempotency->claim($this->idempotencyKeyId)) {
            return;
        }

        try {
            DB::transaction(function () use ($flights,$idempotency): void {
                // Lock and load the flight row so concurrent updates wait.
                $flight = $flights->findForUpdate($this->flightUuid);

                // If the flight still exists, apply the update.
                if ($flight !== null) {
                    // Update legs/segments inside the transaction.
                    $flights->applyPositionalUpdate($flight, $this->legs);
                }

                // Mark the idempotency row completed in the SAME transaction.
                $idempotency->markCompleted(
                  $this->idempotencyKeyId,
                  Response::HTTP_NO_CONTENT,
                );
            });

            $idempotency->markCompleted(
              $this->idempotencyKeyId,
              Response::HTTP_NO_CONTENT,
            );
        } catch (Throwable $e) {
            // The transaction already rolled back partial writes. Mark failed
            // (clearing the lease) and rethrow for the queue's retry handling.
            $idempotency->markFailed($this->idempotencyKeyId);

            throw $e;
        }
    }

    /**
     * Load the idempotency record or throw — used to obtain the key string for
     * the Redis lock.
     */
    private function idempotencyKeyOrThrow(): IdempotencyKey
    {
        return IdempotencyKey::query()->findOrFail($this->idempotencyKeyId);
    }

    /**
     * Final failure hook: invoked after all retries are exhausted.
     */
    public function failed(): void
    {
        app(IdempotencyManager::class)->markFailed($this->idempotencyKeyId);
    }
}