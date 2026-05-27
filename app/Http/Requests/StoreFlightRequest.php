<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Validates the POST /api/flights (create) payload.
 *
 * Creation requires exactly the shared nested validation defined in
 * FlightPayloadRequest (non-empty legs, non-empty segments, per-segment field
 * rules), with nothing extra. We therefore extend the base and add no overrides
 * — keeping the class focused and free of duplicated logic.
 *
 * The controller obtains the clean payload via $request->validatedLegs(),
 * inherited from the base.
 */
final class StoreFlightRequest extends FlightPayloadRequest
{
    // No additional rules or accessors: the base contract is exactly what
    // "create" needs.
}
