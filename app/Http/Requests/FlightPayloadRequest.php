<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Abstract base request for endpoints that accept a nested flight payload.
 *
 * Both "create" and "update" submit the SAME structure:
 *
 *   { "legs": [ { "segments": [ { origin, destination, departure, ... } ] } ] }
 *
 * To honour the "avoid duplicated logic" requirement, all the shared nested
 * validation rules and the typed `validatedLegs()` accessor live here. Concrete
 * requests extend this and add only what is unique to them (e.g. the
 * Idempotency-Key requirement for updates).
 */
abstract class FlightPayloadRequest extends FormRequest
{
    /**
     * Authorize the request.
     *
     * Authentication/authorization for these endpoints is handled by the
     * Api-Key middleware, not per-request policies, so at the Form Request layer
     * we simply allow it through. Returning true here keeps authorization in one
     * place (the middleware) rather than splitting it across layers.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules shared by every flight payload.
     *
     * These encode the spec's validation rules for the nested structure:
     *   - legs: required, non-empty array
     *   - each leg's segments: required, non-empty array
     *   - per-segment field rules (airport codes, datetimes, etc.)
     *
     * The `*` wildcard validates every element of the array, so a payload with
     * any number of legs/segments is fully checked.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A flight must have at least one leg. `array` + `min:1` enforces a
            // non-empty array; `required` rejects a missing/null value.
            'legs' => ['required', 'array', 'min:1'],

            // A leg must have at least one segment.
            'legs.*.segments' => ['required', 'array', 'min:1'],

            // --- Per-segment field rules (apply to every segment in every leg) ---

            // Airport codes: required 3-letter codes. `size:3` enforces exactly
            // three characters; `alpha` ensures they are letters (e.g. "BCN").
            'legs.*.segments.*.origin' => ['required', 'string', 'size:3', 'alpha'],
            'legs.*.segments.*.destination' => ['required', 'string', 'size:3', 'alpha'],

            // Departure: required, parseable datetime. We accept any datetime the
            // payload sends (naive ISO-8601 like "2026-06-09T06:45:00").
            'legs.*.segments.*.departure' => ['required', 'date'],

            // Arrival: required, parseable datetime. The "arrival must be after
            // departure" rule is intentionally NOT expressed here as a wildcard-
            // relative `after:` rule. Nested-wildcard rule expansion in Laravel
            // has subtle, order-sensitive edge cases, and this comparison is a
            // core business rule, so we enforce it explicitly and per-index in
            // the validator hook below (see assertArrivalAfterDeparture()), where
            // we pair each segment's own departure/arrival deterministically.
            'legs.*.segments.*.arrival' => ['required', 'date'],

            // Cabin class is required (e.g. "Y", "J"). Bounded length guards
            // against absurd input while staying permissive about the codes used.
            'legs.*.segments.*.cabinClass' => ['required', 'string', 'max:10'],

            // Operating airline code, required (e.g. "UA").
            'legs.*.segments.*.airline' => ['required', 'string', 'max:10'],

            // Flight number, required. Kept as a string so leading zeros and any
            // alphanumeric suffixes are preserved.
            'legs.*.segments.*.flightNumber' => ['required', 'string', 'max:10'],
        ];
    }

    /**
     * Human-friendly validation messages for the trickier rules.
     *
     * Only the non-obvious rules get custom messages; the rest fall back to
     * Laravel's clear defaults.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'legs.required' => 'At least one leg is required.',
            'legs.min' => 'A flight must contain at least one leg.',
            'legs.*.segments.required' => 'Each leg must contain at least one segment.',
            'legs.*.segments.min' => 'Each leg must contain at least one segment.',
            'legs.*.segments.*.origin.size' => 'Origin must be a 3-letter airport code.',
            'legs.*.segments.*.destination.size' => 'Destination must be a 3-letter airport code.',
        ];
    }

    /**
     * Normalize input before validation.
     *
     * Airport and airline codes are conventionally uppercase. Normalizing here
     * means downstream code and storage are consistent regardless of how the
     * client cased them, and it happens before the rules run so `alpha`/`size`
     * still apply to the cleaned value.
     */
    protected function prepareForValidation(): void
    {
        $legs = $this->input('legs');

        // Guard: only transform when `legs` is actually an array. If it is not,
        // we leave the input untouched so the `array` rule can flag it properly.
        if (! is_array($legs)) {
            return;
        }

        foreach ($legs as $legIndex => $leg) {
            if (! isset($leg['segments']) || ! is_array($leg['segments'])) {
                continue;
            }

            foreach ($leg['segments'] as $segIndex => $segment) {
                // Uppercase the code-like fields when present and scalar.
                foreach (['origin', 'destination', 'airline', 'cabinClass'] as $field) {
                    if (isset($segment[$field]) && is_string($segment[$field])) {
                        $legs[$legIndex]['segments'][$segIndex][$field] =
                            strtoupper(trim($segment[$field]));
                    }
                }
            }
        }

        // Merge the normalized structure back so validation sees cleaned values.
        $this->merge(['legs' => $legs]);
    }

    /**
     * Typed accessor returning ONLY the validated legs array.
     *
     * Controllers and services consume this instead of digging into
     * `validated()` arrays, giving them a clean, predictable contract. Because
     * it draws from `validated()`, it can only ever return data that passed the
     * rules above.
     *
     * @return array<int, array<string, mixed>>
     */
    public function validatedLegs(): array
    {
        /** @var array<int, array<string, mixed>> $legs */
        $legs = $this->validated('legs');

        return $legs;
    }

    /**
     * Hook for subclasses to add cross-field/structural checks after the base
     * rules pass. Default is a no-op; UpdateFlightRequest overrides it.
     */
    protected function withValidatorHook(Validator $validator): void
    {
        // Intentionally empty in the base.
    }

    /**
     * Register validator-level checks.
     *
     * We run TWO things here:
     *   1. The shared business rule that each segment's arrival is strictly after
     *      its departure (enforced explicitly per-index for reliability).
     *   2. The subclass hook, so child requests can layer on their own checks
     *      (e.g. the Idempotency-Key header for updates).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->assertArrivalAfterDeparture($validator);
        });

        $this->withValidatorHook($validator);
    }

    /**
     * Explicitly assert that, for every segment, arrival is strictly after
     * departure.
     *
     * We iterate the actual array indices and compare each segment's OWN pair of
     * timestamps. This avoids relying on Laravel's wildcard-relative `after:`
     * expansion (which has order-sensitive edge cases for nested wildcards) and
     * lets us attach the error to the precise field path the client sent, so the
     * 422 response points at exactly the offending segment.
     */
    protected function assertArrivalAfterDeparture(Validator $validator): void
    {
        $legs = $this->input('legs');

        if (! is_array($legs)) {
            return;
        }

        foreach ($legs as $legIndex => $leg) {
            if (! isset($leg['segments']) || ! is_array($leg['segments'])) {
                continue;
            }

            foreach ($leg['segments'] as $segIndex => $segment) {
                $departure = $segment['departure'] ?? null;
                $arrival = $segment['arrival'] ?? null;

                // If either value is missing/non-scalar, the `required`/`date`
                // rules already produced an error; skip to avoid duplicate noise.
                if (! is_string($departure) || ! is_string($arrival)) {
                    continue;
                }

                // Parse defensively. If either fails to parse, the `date` rule
                // has already flagged it, so we skip the comparison here.
                $departureTs = strtotime($departure);
                $arrivalTs = strtotime($arrival);

                if ($departureTs === false || $arrivalTs === false) {
                    continue;
                }

                // The actual business rule: arrival must be strictly later.
                if ($arrivalTs <= $departureTs) {
                    $validator->errors()->add(
                        "legs.{$legIndex}.segments.{$segIndex}.arrival",
                        'Segment arrival must be after its departure.',
                    );
                }
            }
        }
    }
}
