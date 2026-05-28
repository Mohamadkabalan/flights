<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FlightSegment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FlightSegmentResource.
 *
 * Formats a single FlightSegment as the spec's segment object. This is the leaf
 * of the response tree.
 *
 * Two responsibilities worth noting:
 *   1. Key naming: the DB stores snake_case (cabin_class, flight_number) but the
 *      API contract uses camelCase (cabinClass, flightNumber). We translate here
 *      so the storage layer and the public contract stay independent.
 *   2. Datetime shape: departure/arrival are Carbon instances (model casts), but
 *      the contract mirrors the naive ISO-8601 the client submitted
 *      (e.g. "2026-06-09T06:45:00") — no timezone offset, no fractional seconds.
 *
 * @property-read FlightSegment $resource
 */
final class FlightSegmentResource extends JsonResource
{
    /**
     * The datetime format used in API responses.
     *
     * 'Y-m-d\TH:i:s' produces the naive, second-precision ISO-8601 form that
     * matches the incoming payload exactly, so a round-trip (GET after POST)
     * returns the same string the caller sent.
     */
    private const string DATETIME_FORMAT = 'Y-m-d\TH:i:s';

    /**
     * Transform the segment into its array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $segment = $this->resource;

        return [
            // Airport codes, returned as stored (already normalized to uppercase
            // at validation time).
            'origin' => $segment->origin,
            'destination' => $segment->destination,

            // Carbon -> naive ISO-8601 string. optional() guards against a null
            // value defensively, though these columns are non-nullable.
            'departure' => $segment->departure?->format(self::DATETIME_FORMAT),
            'arrival' => $segment->arrival?->format(self::DATETIME_FORMAT),

            // snake_case column -> camelCase contract key.
            'cabinClass' => $segment->cabin_class,
            'airline' => $segment->airline,
            'flightNumber' => $segment->flight_number,
        ];
    }
}
