<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFlightApi;
use Tests\TestCase;

/**
 * Covers the synchronous endpoints (create, get) and the security/validation
 * guards that apply across the API.
 *
 * Scenarios from the spec exercised here:
 *   - Create a flight with nested legs and segments.
 *   - Get a flight by UUID.
 *   - Api-Key protection (valid key passes).
 *   - Missing / invalid Api-Key (401 / 403).
 *   - Validation errors.
 */
final class FlightCreateAndGetTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFlightApi;

    public function test_it_creates_a_flight_with_nested_legs_and_segments(): void
    {
        $response = $this->postJson('/api/flights', $this->validCreatePayload(), $this->authHeaders());

        // 201 + a flightId in the body.
        $response->assertCreated()
            ->assertJsonStructure(['flightId']);

        $uuid = $response->json('flightId');

        // The aggregate root and the full nested tree were persisted.
        $this->assertDatabaseHas('flights', ['uuid' => $uuid]);
        $this->assertDatabaseCount('flight_legs', 2);
        $this->assertDatabaseCount('flight_segments', 4);

        // Order columns preserve the submitted structure.
        $flight = Flight::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame([1, 2], $flight->legs->pluck('leg_order')->all());
        $this->assertSame([1, 2], $flight->legs->first()->segments->pluck('segment_order')->all());
    }

    public function test_it_returns_a_flight_by_uuid_in_the_expected_shape(): void
    {
        // Arrange: create via the API so the GET reflects a real round-trip.
        $uuid = $this->postJson('/api/flights', $this->validCreatePayload(), $this->authHeaders())
            ->json('flightId');

        // Act
        $response = $this->getJson("/api/flights/{$uuid}", $this->authHeaders());

        // Assert: exact contract shape, unwrapped, camelCase fields, naive ISO.
        $response->assertOk()
            ->assertJson([
                'flightId' => $uuid,
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
                        ],
                    ],
                ],
            ]);

        // No data wrapper at the root.
        $response->assertJsonMissingPath('data');
    }

    public function test_it_returns_404_for_an_unknown_flight(): void
    {
        $this->getJson('/api/flights/' . \Illuminate\Support\Str::uuid()->toString(), $this->authHeaders())
            ->assertNotFound();
    }

    public function test_it_rejects_a_missing_api_key_with_401(): void
    {
        // No Api-Key header at all.
        $this->postJson('/api/flights', $this->validCreatePayload(), ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    public function test_it_rejects_an_invalid_api_key_with_403(): void
    {
        // Present but wrong.
        $this->postJson(
            '/api/flights',
            $this->validCreatePayload(),
            ['Api-Key' => 'wrong-key', 'Accept' => 'application/json'],
        )->assertForbidden();
    }

    public function test_it_rejects_an_empty_legs_array_with_422(): void
    {
        $this->postJson('/api/flights', ['legs' => []], $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('legs');
    }

    public function test_it_rejects_a_leg_with_no_segments_with_422(): void
    {
        $this->postJson('/api/flights', ['legs' => [['segments' => []]]], $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('legs.0.segments');
    }

    public function test_it_rejects_arrival_before_departure_with_422(): void
    {
        $payload = $this->validCreatePayload();
        // Make arrival earlier than departure in the first segment.
        $payload['legs'][0]['segments'][0]['arrival'] = '2026-06-09T05:00:00';

        $this->postJson('/api/flights', $payload, $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('legs.0.segments.0.arrival');
    }

    public function test_it_rejects_a_non_three_letter_airport_code_with_422(): void
    {
        $payload = $this->validCreatePayload();
        $payload['legs'][0]['segments'][0]['origin'] = 'BARCELONA';

        $this->postJson('/api/flights', $payload, $this->authHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('legs.0.segments.0.origin');
    }
}
