<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Flight;
use App\Models\FlightLeg;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FlightCreationService.
 *
 * Owns the business logic for creating a Flight together with its nested Legs
 * and Segments. The controller hands over only the validated `legs` array; this
 * service turns it into a persisted aggregate root.
 *
 * The entire multi-table write happens inside a single database transaction so
 * that a failure half-way through never leaves a partial flight (e.g. a flight
 * with some legs but missing segments). It is all-or-nothing.
 */
final class FlightCreationService
{
    /**
     * Create and persist a flight from validated leg data.
     *
     * @param  array<int, array<string, mixed>>  $legs  Validated legs, each with
     *         a `segments` array of validated segment payloads.
     * @return Flight  The persisted flight (its `uuid` is populated on create).
     *
     * @throws Throwable  Re-thrown after rollback if anything fails mid-write.
     */
    public function create(array $legs): Flight
    {
        // DB::transaction commits on success and rolls back automatically if the
        // closure throws, then re-throws — exactly the all-or-nothing semantics
        // we want for a multi-table insert.
        return DB::transaction(function () use ($legs): Flight {
            // 1. Create the aggregate root. The HasUuids trait fills `uuid`.
            $flight = Flight::create();

            // 2. Create each leg in submitted order, preserving position via
            //    leg_order. We use a 1-based ordinal so the stored order mirrors
            //    human-friendly "leg 1, leg 2" numbering.
            foreach (array_values($legs) as $legIndex => $legData) {
                $leg = $flight->legs()->create([
                    'leg_order' => $legIndex + 1,
                ]);

                // 3. Create that leg's segments in order.
                $this->createSegments($leg, $legData['segments'] ?? []);
            }

            return $flight;
        });
    }

    /**
     * Persist the segments belonging to a single leg, preserving their order.
     *
     * @param  FlightLeg                          $leg       The owning leg.
     * @param  array<int, array<string, mixed>>   $segments  Validated segments.
     */
    private function createSegments(FlightLeg $leg, array $segments): void
    {
        foreach (array_values($segments) as $segIndex => $segment) {
            // Map the camelCase payload keys to the snake_case DB columns. We map
            // explicitly (rather than mass-assigning the raw payload) so the
            // service has full control over what is written and the column names
            // stay decoupled from the API's field naming.
            $leg->segments()->create([
                'segment_order' => $segIndex + 1,
                'origin' => $segment['origin'],
                'destination' => $segment['destination'],
                'departure' => $segment['departure'],
                'arrival' => $segment['arrival'],
                'cabin_class' => $segment['cabinClass'],
                'airline' => $segment['airline'],
                'flight_number' => $segment['flightNumber'],
            ]);
        }
    }
}
