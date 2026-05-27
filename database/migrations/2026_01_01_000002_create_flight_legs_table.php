<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `flight_legs` table. A Leg belongs to exactly one Flight,
     * and the position of the leg within the flight is preserved via `leg_order`.
     * Order matters because the update-matching strategy relies on positional
     * matching (first incoming leg -> first existing leg, etc.).
     */
    public function up(): void
    {
        Schema::create('flight_legs', function (Blueprint $table) {
            // Internal numeric primary key.
            $table->id();

            // Foreign key to the parent flight. `constrained()` automatically
            // points to flights.id. cascadeOnDelete() means deleting a flight
            // deletes its legs, keeping the relational tree consistent and
            // avoiding orphaned rows.
            $table->foreignId('flight_id')
              ->constrained('flights')
              ->cascadeOnDelete();

            // Zero-based (or one-based — see model) ordinal position of this leg
            // within its flight. This is the backbone of the deterministic
            // update-matching strategy: legs are matched by their order.
            $table->unsignedInteger('leg_order');

            $table->timestamps();

            // A given flight cannot have two legs at the same position. This
            // unique constraint guarantees the positional matching strategy is
            // unambiguous and protects against accidental duplicate inserts
            // during concurrent writes.
            $table->unique(['flight_id', 'leg_order']);

            // Index to quickly fetch all legs for a flight ordered by position.
            // (The composite unique above already indexes flight_id as its left-
            //  most column, so a standalone flight_id index would be redundant.
            //  We therefore rely on the unique index for flight_id lookups.)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_legs');
    }
};
