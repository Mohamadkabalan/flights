<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\FlightRepositoryInterface;
use App\Jobs\UpdateFlightJob;
use App\Models\Flight;
use App\Models\IdempotencyKey;
use App\Repositories\FlightRepository;
use App\Support\RequestHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\Concerns\InteractsWithFlightApi;
use Tests\TestCase;

/**
 * Proves that a failure during the update transaction rolls back ALL partial
 * writes, leaving the flight exactly as it was.
 *
 * Strategy: bind a repository decorator whose applyPositionalUpdate makes a real
 * partial change and THEN throws. Because the job wraps the work in
 * DB::transaction, the partial change must be rolled back. We assert the flight
 * is unchanged and the idempotency record is marked failed.
 */
final class TransactionRollbackTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFlightApi;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_a_failure_mid_update_rolls_back_all_changes(): void
    {
        // Arrange: a persisted flight created through the API.
        $uuid = $this->postJson('/api/flights', $this->validCreatePayload(), $this->authHeaders())
            ->json('flightId');

        $flight = Flight::query()->where('uuid', $uuid)->firstOrFail();
        $originalFlightNumber = $flight->legs->firstWhere('leg_order', 1)
            ->segments->firstWhere('segment_order', 1)->flight_number;

        // Register an idempotency record the job will consume.
        $legs = $this->singleLegUpdatePayload()['legs'];
        $record = IdempotencyKey::create([
            'key' => 'idem-rollback',
            'operation' => IdempotencyKey::OPERATION_UPDATE_FLIGHT,
            'flight_id' => $uuid,
            'request_hash' => RequestHasher::hash(['flightId' => $uuid, 'legs' => $legs]),
            'status' => IdempotencyKey::STATUS_PENDING,
        ]);

        // Bind a repository that performs a REAL partial mutation, then throws —
        // simulating a failure after some rows were already written.
        $this->app->bind(FlightRepositoryInterface::class, function ($app) {
            $real = $app->make(FlightRepository::class);

            $decorator = Mockery::mock(FlightRepositoryInterface::class);

            // Delegate the locked read to the real repository.
            $decorator->shouldReceive('findForUpdate')
                ->andReturnUsing(fn (string $u) => $real->findForUpdate($u));

            // Mutate a row, then throw to trigger rollback.
            $decorator->shouldReceive('applyPositionalUpdate')
                ->andReturnUsing(function (Flight $f, array $legs): void {
                    // Real partial write inside the transaction.
                    $segment = $f->legs->firstWhere('leg_order', 1)
                        ->segments->firstWhere('segment_order', 1);
                    $segment->update(['flight_number' => 'PARTIAL']);

                    // Now fail — the transaction must undo the line above.
                    throw new RuntimeException('Simulated failure mid-update.');
                });

            return $decorator;
        });

        // Act: run the job directly. We expect it to rethrow after rollback.
        try {
            (new UpdateFlightJob(
                flightUuid: $uuid,
                legs: $legs,
                idempotencyKeyId: $record->id,
            ))->handle(
                app(\App\Support\IdempotencyManager::class),
                app(FlightRepositoryInterface::class),
            );
            $this->fail('Expected the job to rethrow the simulated failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure mid-update.', $e->getMessage());
        }

        // Assert: the partial write was rolled back — flight number is original.
        $reloaded = Flight::query()->where('uuid', $uuid)->firstOrFail();
        $currentFlightNumber = $reloaded->legs->firstWhere('leg_order', 1)
            ->segments->firstWhere('segment_order', 1)->flight_number;

        $this->assertSame($originalFlightNumber, $currentFlightNumber);
        $this->assertDatabaseMissing('flight_segments', ['flight_number' => 'PARTIAL']);

        // And the idempotency record is marked failed (not stuck processing).
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => 'idem-rollback',
            'status' => IdempotencyKey::STATUS_FAILED,
        ]);
    }
}
