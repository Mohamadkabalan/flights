<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FlightLeg;
use App\Models\FlightSegment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Factory for FlightSegment.
 *
 * Generates plausible segment data for tests/seeders. Departure/arrival are
 * coherent (arrival is always after departure) so generated data satisfies the
 * same business rule the API enforces.
 *
 * @extends Factory<FlightSegment>
 */
final class FlightSegmentFactory extends Factory
{
    protected $model = FlightSegment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // A random departure within the next 30 days, then an arrival 1-6 hours
        // later so arrival > departure always holds.
        $departure = Carbon::now()
            ->addDays(fake()->numberBetween(1, 30))
            ->setTime(fake()->numberBetween(0, 22), fake()->randomElement([0, 15, 30, 45]));

        $arrival = (clone $departure)->addHours(fake()->numberBetween(1, 6));

        return [
            // A leg is created on demand if one is not provided, so a segment can
            // be made standalone in a test.
            'flight_leg_id' => FlightLeg::factory(),
            'segment_order' => 1,
            'origin' => strtoupper(fake()->lexify('???')),
            'destination' => strtoupper(fake()->lexify('???')),
            'departure' => $departure,
            'arrival' => $arrival,
            'cabin_class' => fake()->randomElement(['Y', 'J', 'F', 'W']),
            'airline' => strtoupper(fake()->lexify('??')),
            'flight_number' => (string) fake()->numberBetween(100, 9999),
        ];
    }

    /**
     * State helper to set an explicit position within a leg.
     */
    public function order(int $order): self
    {
        return $this->state(fn () => ['segment_order' => $order]);
    }
}
