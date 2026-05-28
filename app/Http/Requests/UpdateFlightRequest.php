<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;

/**
 * Validates the PUT|POST /api/flights/{flight} (update) payload.
 *
 * The update endpoint accepts the SAME nested body as create (inherited from
 * FlightPayloadRequest), plus two update-specific concerns:
 *
 *   1. The `Idempotency-Key` request header is REQUIRED. It is what guarantees
 *      a given update is processed only once even under retries/concurrency.
 *   2. A typed `idempotencyKey()` accessor so the controller/dispatcher can read
 *      that header without re-parsing it.
 *
 * Header validation is not expressible through the body-oriented rules() array,
 * so we enforce it in a `withValidator` hook, which lets us push a clear,
 * field-scoped error onto the same validation error bag the body uses (HTTP 422).
 */
final class UpdateFlightRequest extends FlightPayloadRequest
{
    /**
     * The canonical header name carrying the idempotency token.
     *
     * Defined once as a constant so the controller, dispatcher, tests, and this
     * request all reference the exact same string.
     */
    public const string HEADER = 'Idempotency-Key';

    /**
     * Add update-specific validation on top of the inherited body rules.
     *
     * This hook runs after the base rules are registered. We use it to assert
     * the Idempotency-Key header is present and well-formed. Pushing the error
     * under an `idempotency_key` key means the client receives a normal 422
     * validation response describing exactly what is missing.
     */
    public function withValidator(Validator $validator): void
    {
        // Run the shared checks from the base (arrival-after-departure) first,
        // then layer on the update-specific Idempotency-Key requirement.
        parent::withValidator($validator);
        $validator->after(function (Validator $validator): void {
            $key = $this->header(self::HEADER);

            // Required: reject a missing or blank header.
            if ($key === null || trim($key) === '') {
                $validator->errors()->add(
                    'idempotency_key',
                    'The ' . self::HEADER . ' header is required for updates.',
                );

                return;
            }

            // Bounded length: the value is persisted in a unique-constrained
            // column, so we cap it to a sane size to avoid oversized keys and to
            // match the storage definition.
            if (mb_strlen($key) > 255) {
                $validator->errors()->add(
                    'idempotency_key',
                    'The ' . self::HEADER . ' header must not exceed 255 characters.',
                );
            }
        });
    }

    /**
     * Typed accessor for the validated Idempotency-Key header value.
     *
     * Safe to call after validation passes: by that point the withValidator
     * hook has guaranteed the header is present and non-empty. We trim to
     * normalize accidental surrounding whitespace so the stored key is exact.
     */
    public function idempotencyKey(): string
    {
        // The header is guaranteed present post-validation; the (string) cast and
        // trim defensively normalize the value.
        return trim((string) $this->header(self::HEADER));
    }
}
