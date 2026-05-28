<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Http\Middleware\EnsureApiKeyIsValid;
use App\Http\Requests\UpdateFlightRequest;

/**
 * Shared helpers for building request payloads and headers in tests.
 *
 * Centralizing these keeps each test focused on the behaviour under test rather
 * than on payload boilerplate, and ensures every test uses a consistent, valid
 * baseline that we can tweak per-scenario.
 */
trait InteractsWithFlightApi
{
    /**
     * The Api-Key used in tests (matches phpunit.xml's API_KEY env).
     */
    protected function apiKey(): string
    {
        return '123456789';
    }

    /**
     * Headers carrying a valid Api-Key, plus JSON accept.
     *
     * @return array<string, string>
     */
    protected function authHeaders(array $extra = []): array
    {
        return array_merge([
            EnsureApiKeyIsValid::HEADER => $this->apiKey(),
            'Accept' => 'application/json',
        ], $extra);
    }

    /**
     * Headers including a valid Api-Key and an Idempotency-Key for updates.
     *
     * @return array<string, string>
     */
    protected function updateHeaders(string $idempotencyKey, array $extra = []): array
    {
        return $this->authHeaders(array_merge([
            UpdateFlightRequest::HEADER => $idempotencyKey,
        ], $extra));
    }

    /**
     * A valid two-leg create payload mirroring the spec example.
     *
     * @return array<string, mixed>
     */
    protected function validCreatePayload(): array
    {
        return [
            'legs' => [
                [
                    'segments' => [
                        [
                            'origin' => 'BCN',
                            'destination' => 'LON',
                            'departure' => '2026-06-09T06:45:00',
                            'arrival' => '2026-06-09T10:55:00',
                            'cabinClass' => 'Y',
                            'airline' => 'UA',
                            'flightNumber' => '101',
                        ],
                        [
                            'origin' => 'LON',
                            'destination' => 'JFK',
                            'departure' => '2026-06-09T11:55:00',
                            'arrival' => '2026-06-09T14:55:00',
                            'cabinClass' => 'Y',
                            'airline' => 'UA',
                            'flightNumber' => '102',
                        ],
                    ],
                ],
                [
                    'segments' => [
                        [
                            'origin' => 'JFK',
                            'destination' => 'LON',
                            'departure' => '2026-06-25T06:45:00',
                            'arrival' => '2026-06-25T10:55:00',
                            'cabinClass' => 'Y',
                            'airline' => 'UA',
                            'flightNumber' => '101',
                        ],
                        [
                            'origin' => 'LON',
                            'destination' => 'BCN',
                            'departure' => '2026-06-25T11:55:00',
                            'arrival' => '2026-06-25T13:55:00',
                            'cabinClass' => 'Y',
                            'airline' => 'UA',
                            'flightNumber' => '102',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * A single-leg update payload (a partial update of leg #1) with a small
     * time shift, mirroring the spec's update example.
     *
     * @return array<string, mixed>
     */
    protected function singleLegUpdatePayload(): array
    {
        return [
            'legs' => [
                [
                    'segments' => [
                        [
                            'origin' => 'BCN',
                            'destination' => 'LON',
                            'departure' => '2026-06-09T06:40:00',
                            'arrival' => '2026-06-09T10:50:00',
                            'cabinClass' => 'Y',
                            'airline' => 'UA',
                            'flightNumber' => '101',
                        ],
                        [
                            'origin' => 'LON',
                            'destination' => 'JFK',
                            'departure' => '2026-06-09T11:55:00',
                            'arrival' => '2026-06-09T14:55:00',
                            'cabinClass' => 'Y',
                            'airline' => 'UA',
                            'flightNumber' => '102',
                        ],
                    ],
                ],
            ],
        ];
    }
}
