<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Flight;
use App\Models\FlightLeg;
use App\Contracts\FlightRepositoryInterface;
/**
 * FlightRepository.
 *
 * Encapsulates persistence concerns for the Flight aggregate that go beyond
 * trivial model calls — specifically the positional update-matching algorithm
 * and the pessimistic locking used during updates. Keeping this here means the
 * job stays focused on orchestration (lock idempotency, open transaction) while
 * the repository owns the "how do rows change" details.
 *
 * MATCHING STRATEGY (documented here as the source of truth):
 * ----------------------------------------------------------
 * Update payloads carry no leg/segment IDs, so matching is POSITIONAL:
 *
 *   - Incoming leg at index i  -> existing leg with leg_order = i + 1.
 *   - Incoming segment index j -> existing segment with segment_order = j + 1.
 *
 *   - PARTIAL update: if the payload has fewer legs than exist, only the
 *     overlapping leading legs are updated; trailing existing legs are left
 *     untouched. (One incoming leg updates only leg #1.)
 *
 *   - Within a matched leg, segments are updated in place where positions align.
 *     If the incoming leg has a DIFFERENT number of segments than the existing
 *     leg, the existing leg's segments are reconciled to mirror the submitted
 *     structure: surplus existing segments are deleted, and missing ones created.
 *     This is the "existing structure requires replacement" case from the spec.
 *
 *   - We never add new LEGS beyond those that already exist; an incoming leg with
 *     no positional counterpart is ignored (the contract is "synchronize known
 *     legs", not "grow the flight").
 */
final class FlightRepository implements FlightRepositoryInterface
{
    /**
     * Fetch a flight by UUID with a pessimistic write lock on its row, eager-
     * loading the full ordered tree.
     *
     * The lockForUpdate() places a row-level lock (SELECT ... FOR UPDATE) so that
     * two transactions updating the SAME flight serialize: the second blocks
     * until the first commits. This is the database-level guard against
     * concurrent structural updates corrupting the tree. MUST be called inside a
     * transaction to have any effect.
     */
    public function findForUpdate(string $uuid): ?Flight
    {
        /** @var Flight|null $flight */
        $flight = Flight::query()
            ->where('uuid', $uuid)
            ->lockForUpdate()
            ->first();

        if ($flight === null) {
            return null;
        }

        // Load the ordered tree so positional matching works against the current
        // persisted structure.
        $flight->load([
            'legs' => fn ($q) => $q->orderBy('leg_order'),
            'legs.segments' => fn ($q) => $q->orderBy('segment_order'),
        ]);

        return $flight;
    }

    /**
     * Apply a positional update to a flight from the incoming legs payload.
     *
     * Assumes it is called inside a transaction with the flight already locked
     * (see findForUpdate). It mutates rows in place and reconciles segment counts
     * per the documented matching strategy.
     *
     * @param  Flight                             $flight  Locked flight aggregate.
     * @param  array<int, array<string, mixed>>   $legs    Validated incoming legs.
     */
    public function applyPositionalUpdate(Flight $flight, array $legs): void
    {
        // Index existing legs by their 1-based position for O(1) lookup.
        $existingLegs = $flight->legs->keyBy('leg_order');

        foreach (array_values($legs) as $index => $legData) {
            $position = $index + 1;

            // No existing leg at this position -> ignore (we do not grow flights).
            /** @var FlightLeg|null $leg */
            $leg = $existingLegs->get($position);
            if ($leg === null) {
                continue;
            }

            $this->reconcileSegments($leg, $legData['segments'] ?? []);
        }
    }

    /**
     * Reconcile one leg's segments against the incoming segment list.
     *
     * Strategy:
     *   - For each incoming segment position, UPDATE the existing segment in
     *     place if one exists, else CREATE it.
     *   - DELETE any existing segments whose position is beyond the incoming
     *     count (structure shrank).
     *
     * @param  FlightLeg                          $leg       Locked leg.
     * @param  array<int, array<string, mixed>>   $segments  Incoming segments.
     */
    private function reconcileSegments(FlightLeg $leg, array $segments): void
    {
        $existingSegments = $leg->segments->keyBy('segment_order');
        $incomingCount = count($segments);

        // Upsert each incoming segment by position.
        foreach (array_values($segments) as $segIndex => $segmentData) {
            $position = $segIndex + 1;
            $attributes = $this->mapSegmentAttributes($segmentData, $position);

            $existing = $existingSegments->get($position);

            if ($existing !== null) {
                // Update in place — preserves the row id, just shifts its data.
                $existing->update($attributes);
            } else {
                // Position did not exist before -> create it under this leg.
                $leg->segments()->create($attributes);
            }
        }

        // Remove existing segments beyond the new structure's length.
        foreach ($existingSegments as $order => $segment) {
            if ($order > $incomingCount) {
                $segment->delete();
            }
        }
    }

    /**
     * Map a validated camelCase segment payload to snake_case column attributes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapSegmentAttributes(array $data, int $position): array
    {
        return [
            'segment_order' => $position,
            'origin' => $data['origin'],
            'destination' => $data['destination'],
            'departure' => $data['departure'],
            'arrival' => $data['arrival'],
            'cabin_class' => $data['cabinClass'],
            'airline' => $data['airline'],
            'flight_number' => $data['flightNumber'],
        ];
    }
}
