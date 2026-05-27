<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FlightSegment.
 *
 * The leaf of the domain tree. A Segment belongs to exactly one Leg and carries
 * the concrete travel details. Its position within the leg is stored in
 * `segment_order`, used for positional update-matching.
 *
 * @property int                        $id
 * @property int                        $flight_leg_id
 * @property int                        $segment_order
 * @property string                     $origin
 * @property string                     $destination
 * @property \Illuminate\Support\Carbon $departure
 * @property \Illuminate\Support\Carbon $arrival
 * @property string                     $cabin_class
 * @property string                     $airline
 * @property string                     $flight_number
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read FlightLeg             $leg
 */
final class FlightSegment extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     *
     * All domain columns are fillable because the persistence layer builds a
     * segment directly from validated payload data. `flight_leg_id` and
     * `segment_order` are set when wiring the tree together.
     *
     * @var list<string>
     */
    protected $fillable = [
        'flight_leg_id',
        'segment_order',
        'origin',
        'destination',
        'departure',
        'arrival',
        'cabin_class',
        'airline',
        'flight_number',
    ];

    /**
     * Attribute casting.
     *
     * - segment_order -> integer for strict positional comparisons.
     * - departure / arrival -> 'datetime' so Eloquent hydrates them as Carbon
     *   instances. We deliberately use the plain 'datetime' cast (not
     *   'datetime:...Tz') because the payload carries naive local timestamps with
     *   no offset; we store and return them as-is to round-trip faithfully.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'segment_order' => 'integer',
            'departure' => 'datetime',
            'arrival' => 'datetime',
        ];
    }

    /**
     * The owning leg.
     */
    public function leg(): BelongsTo
    {
        // Explicit foreign key for clarity even though it follows convention.
        return $this->belongsTo(FlightLeg::class, 'flight_leg_id');
    }

    /**
     * Scope: order segments by their position within a leg.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('segment_order');
    }
}
