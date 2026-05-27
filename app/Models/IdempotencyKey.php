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
 * `Idempotency-Key` header. It powers three guarantees:
 *
 *   1. Deduplication      - a (key, operation) pair is processed once.
 *   2. Concurrency safety - the unique constraint + status transitions stop two
 *                           simultaneous requests with the same key proceeding.
 *   3. Replay             - completed requests can return their stored outcome.
 *
 * @property int                        $id
 * @property string                     $key
 * @property string|null                $flight_id
 * @property string                     $operation
 * @property string                     $status
 * @property string|null                $request_hash
 * @property int|null                   $response_code
 * @property \Illuminate\Support\Carbon|null $locked_until
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class IdempotencyKey extends Model
{
    use HasFactory;
    use Prunable;

    // ---------------------------------------------------------------------
    // Lifecycle status constants.
    //
    // Using class constants instead of bare strings prevents typos, gives one
    // authoritative source of truth, and makes status transitions self-
    // documenting throughout the codebase.
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
        'response_code',
        'locked_until',
        'processed_at',
    ];

    /**
     * Attribute casting.
     *
     * Cast the two lifecycle timestamps to Carbon so we can do expressive,
     * type-safe comparisons (e.g. $key->locked_until->isPast()). response_code
     * is cast to integer so it round-trips as an int rather than a string.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'locked_until' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------------
    // Convenience predicates — read as plain English at call sites.
    // ---------------------------------------------------------------------

    /**
     * Has this request already finished successfully? If so, callers should
     * replay the stored response instead of re-running the work.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Is this key currently being processed AND still within its lock lease?
     *
     * A row counts as "actively locked" only when its status is `processing`
     * and `locked_until` is in the future. If the lease has expired, the worker
     * is presumed dead and another worker may reclaim the row.
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
     * Scope: find a row by its (key, operation) natural key — the same pair the
     * unique constraint enforces. This is the canonical lookup the API and job
     * use to locate an idempotency record.
     */
    public function scopeForKey(Builder $query, string $key, string $operation): Builder
    {
        return $query->where('key', $key)->where('operation', $operation);
    }

    /**
     * Scope: rows that are "stale" — marked processing but whose lock lease has
     * already expired. A maintenance/reclaim process can pick these up so a
     * crashed worker never strands a key forever.
     */
    public function scopeStaleLocks(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING)
            ->whereNotNull('locked_until')
            ->where('locked_until', '<', Carbon::now());
    }
    /**
     * Records eligible for pruning: completed or failed keys older than 7 days.
     *
     * Keeps the table small so the unique-constraint lookups that idempotency
     * depends on stay fast. In-flight keys (pending/processing) are never pruned.
     */
    public function prunable(): Builder
    {
        return static::query()
          ->whereIn('status', [self::STATUS_COMPLETED, self::STATUS_FAILED])
          ->where('created_at', '<', Carbon::now()->subDays(7));
    }
}
