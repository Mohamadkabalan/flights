<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Flight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FlightResource.
 *
 * Top-level formatter for the GET /api/flights/{flight} response. Produces the
 * exact shape the spec requires:
 *
 *   {
 *     "flightId": "uuid",
 *     "legs": [ { "segments": [ { ... } ] } ]
 *   }
 *
 * @property-read Flight $resource
 */
final class FlightResource extends JsonResource
{
    /**
     * Disable the default top-level "data" wrapper.
     *
     * By default Laravel wraps resource output in a { "data": ... } envelope.
     * The contract here is unwrapped, so we null out the wrapper for this
     * resource to emit flightId/legs at the root.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * Transform the flight into its array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $flight = $this->resource;

        return [
            // Public UUID — never the internal auto-increment id.
            'flightId' => $flight->uuid,

            // Legs are eager-loaded in submitted order by the controller; each is
            // delegated to FlightLegResource. The collection preserves order.
            'legs' => FlightLegResource::collection($flight->legs),
        ];
    }
}
