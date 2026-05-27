<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FlightLeg.
 *
 * A Leg belongs to exactly one Flight and owns one or more Segments. Its
 * position within the flight is stored in `leg_order`, which drives the
 * deterministic, position-based update-matching strategy.
 *
 * @property int                                                                $id
 * @property int                                                                $flight_id
 * @property int                                                                $leg_order
 * @property \Illuminate\Support\Carbon                                         $created_at
 * @property \Illuminate\Support\Carbon                                         $updated_at
 * @property-read Flight                                                        $flight
 * @property-read \Illuminate\Database\Eloquent\Collection<int, FlightSegment>  $segments
 */
final class FlightLeg extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * `flight_id` and `leg_order` are set by our persistence layer when building
     * the tree, so they are explicitly fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'flight_id',
        'leg_order',
    ];

    /**
     * Attribute casting.
     *
     * leg_order is an integer in the DB; casting guarantees it is an int in PHP
     * (not a numeric string), which keeps positional comparisons strict.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'leg_order' => 'integer',
        ];
    }

    /**
     * The owning flight.
     */
    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    /**
     * A leg has many segments.
     *
     * Ordered by `segment_order` so segments are always returned in their
     * submitted sequence — required for positional matching during updates and
     * for a deterministic GET response.
     */
    public function segments(): HasMany
    {
        return $this->hasMany(FlightSegment::class)->orderBy('segment_order');
    }

    /**
     * Scope: order legs by their position. Useful when querying legs directly
     * (outside the Flight::legs relationship which already orders them).
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('leg_order');
    }
}
