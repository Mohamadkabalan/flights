<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\UpdateFlightJob;
use App\Models\Flight;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithFlightApi;
use Tests\TestCase;

/**
 * Covers the asynchronous update flow and its idempotency/concurrency
 * guarantees.
 *
 * Scenarios from the spec exercised here:
 *   - Update one leg asynchronously (partial update).
 *   - Update all legs asynchronously (full update).
 *   - Idempotency-Key prevents duplicate update processing.
 *   - Concurrent duplicate update requests with the same Idempotency-Key.
 *
 * Note on async testing: the test environment uses QUEUE_CONNECTION=sync, so a
 * dispatched job runs inline. This lets us assert the JOB'S EFFECT (the DB was
 * updated) deterministically. Where we need to assert dispatch COUNTS (dedup),
 * we use Queue::fake().
 */
final class FlightUpdateTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFlightApi;

    /**
     * Helper: create a flight via the API and return its UUID.
     */
    private function createFlight(): string
    {
        return $this->postJson('/api/flights', $this->validCreatePayload(), $this->authHeaders())
            ->json('flightId');
    }

    public function test_it_updates_one_leg_asynchronously_and_returns_204(): void
    {
        $uuid = $this->createFlight();

        // A partial (single-leg) update with a small time shift on leg #1.
        $response = $this->putJson(
            "/api/flights/{$uuid}",
            $this->singleLegUpdatePayload(),
            $this->updateHeaders('idem-one-leg'),
        );

        $response->assertNoContent();

        // The job ran inline (sync queue) and applied the shift to leg #1...
        $flight = Flight::query()->where('uuid', $uuid)->firstOrFail();
        $firstSegment = $flight->legs->firstWhere('leg_order', 1)->segments->firstWhere('segment_order', 1);
        $this->assertSame('2026-06-09 06:40:00', $firstSegment->departure->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-09 10:50:00', $firstSegment->arrival->format('Y-m-d H:i:s'));

        // ...while leg #2 is left untouched (partial update isolates leg #1).
        $secondLegFirstSeg = $flight->legs->firstWhere('leg_order', 2)->segments->firstWhere('segment_order', 1);
        $this->assertSame('JFK', $secondLegFirstSeg->origin);
        $this->assertSame('2026-06-25 06:45:00', $secondLegFirstSeg->departure->format('Y-m-d H:i:s'));

        // The idempotency record was completed.
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => 'idem-one-leg',
            'status' => IdempotencyKey::STATUS_COMPLETED,
        ]);
    }

    public function test_it_updates_all_legs_asynchronously(): void
    {
        $uuid = $this->createFlight();

        // Full update: change a field in BOTH legs.
        $payload = $this->validCreatePayload();
        $payload['legs'][0]['segments'][0]['flightNumber'] = '999';
        $payload['legs'][1]['segments'][0]['flightNumber'] = '888';

        $this->putJson("/api/flights/{$uuid}", $payload, $this->updateHeaders('idem-all-legs'))
            ->assertNoContent();

        $flight = Flight::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(
            '999',
            $flight->legs->firstWhere('leg_order', 1)->segments->firstWhere('segment_order', 1)->flight_number,
        );
        $this->assertSame(
            '888',
            $flight->legs->firstWhere('leg_order', 2)->segments->firstWhere('segment_order', 1)->flight_number,
        );
    }

    public function test_the_same_idempotency_key_dispatches_the_job_only_once(): void
    {
        // Fake the queue so we can count dispatches precisely.
        Queue::fake();

        $uuid = $this->createFlight();
        $headers = $this->updateHeaders('idem-dup');

        // First request: accepted, job dispatched, 204.
        $this->putJson("/api/flights/{$uuid}", $this->singleLegUpdatePayload(), $headers)
            ->assertNoContent();

        // Second identical request (retry): still 204, but NO new job.
        $this->putJson("/api/flights/{$uuid}", $this->singleLegUpdatePayload(), $headers)
            ->assertNoContent();

        // Exactly one job was pushed across both requests.
        Queue::assertPushed(UpdateFlightJob::class, 1);

        // And only one idempotency row exists for the key.
        $this->assertSame(1, IdempotencyKey::query()->where('key', 'idem-dup')->count());
    }

    public function test_concurrent_duplicate_requests_with_same_key_register_once(): void
    {
        // Simulate concurrent submissions: same key, same body, fired back to
        // back. The unique (key, operation) constraint must admit only one.
        Queue::fake();

        $uuid = $this->createFlight();
        $headers = $this->updateHeaders('idem-concurrent');
        $payload = $this->singleLegUpdatePayload();

        // Two near-simultaneous requests.
        $first = $this->putJson("/api/flights/{$uuid}", $payload, $headers);
        $second = $this->putJson("/api/flights/{$uuid}", $payload, $headers);

        // Both return 204 (the retry is observably identical to the first).
        $first->assertNoContent();
        $second->assertNoContent();

        // Only ONE row and ONE dispatch survived the race.
        $this->assertSame(1, IdempotencyKey::query()->where('key', 'idem-concurrent')->count());
        Queue::assertPushed(UpdateFlightJob::class, 1);
    }

    public function test_same_key_with_different_body_is_rejected_with_409_or_422(): void
    {
        $uuid = $this->createFlight();
        $headers = $this->updateHeaders('idem-conflict');

        // First request establishes the key.
        $this->putJson("/api/flights/{$uuid}", $this->singleLegUpdatePayload(), $headers)
            ->assertNoContent();

        // Second request reuses the key but with a DIFFERENT body.
        $different = $this->singleLegUpdatePayload();
        $different['legs'][0]['segments'][0]['flightNumber'] = 'CHANGED';

        $this->putJson("/api/flights/{$uuid}", $different, $headers)
            ->assertStatus(422);
    }

    public function test_update_requires_an_idempotency_key_header(): void
    {
        $uuid = $this->createFlight();

        // No Idempotency-Key header -> validation error.
        $this->putJson("/api/flights/{$uuid}", $this->singleLegUpdatePayload(), $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }
}
