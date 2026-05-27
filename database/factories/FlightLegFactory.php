<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Flight;
use App\Models\FlightLeg;
use App\Models\FlightSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for FlightLeg.
 *
 * @extends Factory<FlightLeg>
 */
final class FlightLegFactory extends Factory
{
    protected $model = FlightLeg::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Create an owning flight on demand if none supplied.
            'flight_id' => Flight::factory(),
            'leg_order' => 1,
        ];
    }

    /**
     * State helper to set an explicit position within a flight.
     */
    public function order(int $order): self
    {
        return $this->state(fn () => ['leg_order' => $order]);
    }

    /**
     * Attach a given number of ordered segments to the leg after creation.
     *
     * Segments are numbered 1..N so they satisfy the (flight_leg_id,
     * segment_order) unique constraint and mirror real submitted structure.
     */
    public function withSegments(int $count = 2): self
    {
        return $this->afterCreating(function (FlightLeg $leg) use ($count): void {
            for ($i = 1; $i <= $count; $i++) {
                FlightSegment::factory()
                    ->for($leg, 'leg')
                    ->order($i)
                    ->create();
            }
        });
    }
}
