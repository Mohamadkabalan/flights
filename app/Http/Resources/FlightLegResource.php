<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FlightLeg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * FlightLegResource.
 *
 * Formats a single FlightLeg as the spec's leg object: { "segments": [ ... ] }.
 *
 * The leg's own internal fields (id, leg_order) are intentionally NOT exposed —
 * the contract only surfaces the segments. Ordering is guaranteed upstream:
 * the Flight::legs / FlightLeg::segments relationships order by their *_order
 * columns, and the controller eager-loads them ordered, so the collection here
 * is already in submitted sequence.
 *
 * @property-read FlightLeg $resource
 */
final class FlightLegResource extends JsonResource
{
    /**
     * Transform the leg into its array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FlightLeg $leg */
        $leg = $this->resource;

        return [
            // Delegate each segment to FlightSegmentResource. Using the resource
            // collection keeps formatting responsibility in one place per level
            // and composes the tree cleanly.
            'segments' => FlightSegmentResource::collection($leg->segments),
        ];
    }
}
