<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;

/**
 * IdempotencyKey.
 *
 * Durable record of a write request keyed by the client-supplied
 * `Idempotency-Key` header. It powers:
 *
 *   1. Deduplication      - a (key, operation) pair is processed once.
 *   2. Concurrency safety - the unique constraint + status transitions stop two
 *                           simultaneous requests with the same key proceeding.
 *   3. Replay             - completed requests can return their stored outcome.
 *   4. Recovery           - the persisted request_payload lets a lost job be
 *                           re-dispatched (see RedispatchStuckFlightUpdates).
 *
 * @property int                             $id
 * @property string                          $key
 * @property string|null                     $flight_id
 * @property string                          $operation
 * @property string                          $status
 * @property string|null                     $request_hash
 * @property array<string, mixed>|null       $request_payload
 * @property int|null                        $response_code
 * @property \Illuminate\Support\Carbon|null $locked_until
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property \Illuminate\Support\Carbon      $created_at
 * @property \Illuminate\Support\Carbon      $updated_at
 */
final class IdempotencyKey extends Model
{
    use HasFactory;
    use Prunable;

    // ---------------------------------------------------------------------
    // Lifecycle status constants.
    // ---------------------------------------------------------------------

    /** Accepted by the API; job dispatched, not yet started. */
    public const STATUS_PENDING = 'pending';

    /** A worker has claimed this row and is actively processing it. */
    public const STATUS_PROCESSING = 'processing';

    /** The guarded operation finished successfully. */
    public const STATUS_COMPLETED = 'completed';

    /** The guarded operation failed terminally (after retries). */
    public const STATUS_FAILED = 'failed';

    // ---------------------------------------------------------------------
    // Operation constants — the logical write this key guards.
    // ---------------------------------------------------------------------

    /** The single operation guarded in this application. */
    public const OPERATION_UPDATE_FLIGHT = 'update_flight';

    /**
     * Mass-assignable attributes.
     *
     * @var list<string>
     */
    protected $fillable = [
      'key',
      'flight_id',
      'operation',
      'status',
      'request_hash',
      'request_payload',
      'response_code',
      'locked_until',
      'processed_at',
    ];

    /**
     * Attribute casting.
     *
     * request_payload is cast to array so the stored JSON body round-trips as a
     * PHP array for re-dispatch.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
          'response_code' => 'integer',
          'request_payload' => 'array',
          'locked_until' => 'datetime',
          'processed_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------------
    // Convenience predicates.
    // ---------------------------------------------------------------------

    /**
     * Has this request already finished successfully?
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Is this key currently being processed AND still within its lock lease?
     */
    public function isActivelyLocked(): bool
    {
        return $this->status === self::STATUS_PROCESSING
          && $this->locked_until !== null
          && $this->locked_until->isFuture();
    }

    // ---------------------------------------------------------------------
    // Query scopes.
    // ---------------------------------------------------------------------

    /**
     * Scope: find a row by its (key, operation) natural key.
     */
    public function scopeForKey(Builder $query, string $key, string $operation): Builder
    {
        return $query->where('key', $key)->where('operation', $operation);
    }

    /**
     * Scope: rows marked processing but whose lock lease has already expired.
     */
    public function scopeStaleLocks(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING)
          ->whereNotNull('locked_until')
          ->where('locked_until', '<', Carbon::now());
    }

    /**
     * Scope: PENDING rows older than $olderThanSeconds — candidates for
     * re-dispatch.
     *
     * A row is only ever PENDING in the brief window between registration and
     * the job claiming it. A PENDING row that is minutes old therefore means its
     * job never reached a worker (the queue backend lost it after commit), so it
     * should be re-dispatched. Re-dispatch is safe: the job's claim() admits
     * exactly one runner, so a row whose job actually did run is no longer
     * PENDING and won't be selected.
     */
    public function scopeStalePending(Builder $query, int $olderThanSeconds = 300): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
          ->where('created_at', '<', Carbon::now()->subSeconds($olderThanSeconds));
    }

    /**
     * Records eligible for pruning: completed or failed keys older than 7 days.
     */
    public function prunable(): Builder
    {
        return static::query()
          ->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_FAILED])
          ->where('created_at', '<', Carbon::now()->subDays(7));
    }
}