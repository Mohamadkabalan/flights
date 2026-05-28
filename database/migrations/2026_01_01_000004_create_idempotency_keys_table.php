<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `idempotency_keys` table. This table is the durable record of
     * every update request keyed by its client-supplied `Idempotency-Key`.
     *
     * It serves three purposes:
     *   1. Deduplication  - the same key is only ever processed once.
     *   2. Concurrency control - a unique constraint + status transitions stop
     *      two simultaneous requests with the same key from both proceeding.
     *   3. Replay - once a request is completed we can return the stored
     *      response code without re-running the work.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            // Internal numeric primary key.
            $table->id();

            // The client-supplied Idempotency-Key header value. This is the
            // natural key for deduplication. We make it unique so the database
            // itself enforces "process this key once" — even under a race, only
            // one INSERT can win.
            // Bounded length: this column carries a unique index, so we cap it
            // to match the 255-char limit the UpdateFlightRequest enforces.
            $table->string('key', 255);

            // The flight this update targets. Nullable because the row may be
            // created before/independently of resolving the flight, and stored
            // as a UUID string to match our public identifier. Indexed for
            // lookups that join idempotency records back to a flight.
            $table->uuid('flight_id')->nullable();

            // The logical operation this key guards, e.g. "update_flight".
            // Including the operation lets us scope keys per-operation if we ever
            // reuse the same table for other write operations.
            $table->string('operation');

            // Processing lifecycle state. Typical transitions:
            //   pending    -> the request was accepted and a job was dispatched
            //   processing -> the job has claimed the row and is working
            //   completed  -> the update finished successfully
            //   failed     -> the job failed terminally (after retries)
            // Default 'pending' reflects the state right after the API accepts it.
            $table->string('status')->default('pending');

            // A hash (e.g. SHA-256) of the canonicalized request body. This lets
            // us detect when a client reuses the SAME key with a DIFFERENT body,
            // which is a client error and must be rejected (HTTP 422), rather
            // than silently returning the original result.
            $table->string('request_hash', 64)->nullable();

            $table->json('request_payload')->nullable();
            // The HTTP status code we ultimately returned/finalized for this key.
            // On a replayed request we can surface this stored outcome.
            $table->unsignedSmallInteger('response_code')->nullable();

            // Optional lock expiry. When a worker claims this row it can set
            // `locked_until` to "now + lease". If the worker dies, another worker
            // may reclaim the row after the lease expires, preventing a stuck key.
            $table->dateTime('locked_until')->nullable();

            // Timestamp marking when processing finished. Useful for auditing,
            // metrics, and for cleanup jobs that purge old completed keys.
            $table->dateTime('processed_at')->nullable();

            $table->timestamps();

            // The cornerstone of idempotency: the same key for the same
            // operation can exist only once. A duplicate concurrent submission
            // will fail this unique constraint, which our application catches and
            // treats as "already in flight / already done".
            $table->unique(['key', 'operation']);

            // Fast lookups of all idempotency records for a given flight.
            $table->index('flight_id');

            // Supports cleanup/monitoring queries that scan by lifecycle state,
            // e.g. finding stale 'processing' rows whose lock has expired.
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
