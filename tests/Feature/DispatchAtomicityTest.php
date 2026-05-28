<?php

declare(strict_types=1);
namespace Tests\Feature;

use App\Jobs\UpdateFlightJob;
use App\Models\Flight;
use App\Models\IdempotencyKey;
use App\Services\FlightUpdateDispatcher;
use App\Support\RequestHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\InteractsWithFlightApi;
use Tests\TestCase;
/**
 * Proves the dispatch-after-commit coupling:
 *   - A rollback of the surrounding transaction queues NO job (no orphan state).
 *   - A committed registration whose job was "lost" is recovered by the
 *     scheduled re-dispatch command.
 */
final class DispatchAtomicityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFlightApi;
    private function createFlight(): string
    {
        return $this->postJson('/api/flights', $this->validCreatePayload(), $this->authHeaders())
          ->json('flightId');
    }

    public function test_no_job_is_dispatched_when_the_surrounding_transaction_rolls_back(): void
    {
        Queue::fake();
        $uuid = $this->createFlight();
        $flight = Flight::query()->where('uuid', $uuid)->firstOrFail();
        try {
            DB::transaction(function () use ($flight): void {
                app(FlightUpdateDispatcher::class)->dispatch(
                  $flight,
                  $this->singleLegUpdatePayload()['legs'],
                  'idem-rollback-dispatch',
                );
                // Force the outer transaction to roll back AFTER dispatch ran.
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }
        // The row was rolled back AND the job was never queued.
        $this->assertSame(
          0,
          IdempotencyKey::query()->where('key', 'idem-rollback-dispatch')->count(),
        );
        Queue::assertPushed(UpdateFlightJob::class, 0);
    }

    public function test_recovery_command_redispatches_a_stuck_pending_row(): void
    {
        Queue::fake();
        $uuid = $this->createFlight();
        $legs = $this->singleLegUpdatePayload()['legs'];
        // Simulate a committed registration whose job was lost: a PENDING row,
        // backdated so it is older than the command's age threshold, with the
        // payload persisted.
        $record=IdempotencyKey::create([
          'key' => 'idem-lost-job',
          'operation' => IdempotencyKey::OPERATION_UPDATE_FLIGHT,
          'flight_id' => $uuid,
          'request_hash' => RequestHasher::hash(['flightId' => $uuid, 'legs' => $legs]),
          'request_payload' => ['legs' => $legs],
          'status' => IdempotencyKey::STATUS_PENDING
        ]);
        // Backdate created_at AFTER creation. Passing it into create() does not
        // work — Eloquent auto-manages timestamps and overwrites it with now().
        // A raw query update bypasses that, so the row is genuinely stale.
        IdempotencyKey::query()
          ->whereKey($record->id)
          ->update(['created_at' => Carbon::now()->subMinutes(10)]);

        $this->artisan('flights:redispatch-stuck', ['--age' => 300])
          ->assertSuccessful();
        // The lost job was re-dispatched exactly once.
        Queue::assertPushed(UpdateFlightJob::class, 1);
    }

    public function test_recovery_command_ignores_fresh_pending_rows(): void
    {
        Queue::fake();
        $uuid = $this->createFlight();
        $legs = $this->singleLegUpdatePayload()['legs'];
        // A PENDING row created just now is within the normal processing window
        // and must NOT be re-dispatched.
        IdempotencyKey::create([
          'key' => 'idem-fresh',
          'operation' => IdempotencyKey::OPERATION_UPDATE_FLIGHT,
          'flight_id' => $uuid,
          'request_hash' => RequestHasher::hash(['flightId' => $uuid, 'legs' => $legs]),
          'request_payload' => ['legs' => $legs],
          'status' => IdempotencyKey::STATUS_PENDING,
        ]);
        $this->artisan('flights:redispatch-stuck', ['--age' => 300])
          ->assertSuccessful();
        Queue::assertPushed(UpdateFlightJob::class, 0);
    }
}