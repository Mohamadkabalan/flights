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
     * Creates the `flight_segments` table. A Segment belongs to exactly one Leg.
     * Its position within the leg is preserved via `segment_order`, which (like
     * leg_order) drives the deterministic positional update-matching strategy.
     */
    public function up(): void
    {
        Schema::create('flight_segments', function (Blueprint $table) {
            // Internal numeric primary key.
            $table->id();

            // Foreign key to the parent leg. Deleting a leg cascades to delete
            // its segments, preventing orphaned segment rows.
            $table->foreignId('flight_leg_id')
              ->constrained('flight_legs')
              ->cascadeOnDelete();

            // Ordinal position of this segment within its leg. Used to match
            // an incoming segment to the existing segment at the same index.
            $table->unsignedInteger('segment_order');

            // --- Domain attributes of a Segment (per the spec) ---

            // 3-letter IATA airport codes. We size as char(3) because these are
            // fixed-length codes; char is marginally more storage-efficient and
            // signals the fixed-length intent. Validation enforces the format at
            // the application layer.
            $table->char('origin', 3);
            $table->char('destination', 3);

            // Local scheduled departure/arrival timestamps. Stored as DATETIME
            // (no timezone). The incoming payload uses naive ISO-8601 strings
            // such as "2026-06-09T06:45:00" with no offset, so we persist them
            // as-is to faithfully round-trip what the caller submitted.
            $table->dateTime('departure');
            $table->dateTime('arrival');

            // Cabin class code, e.g. "Y" (economy), "J" (business). Short string.
            $table->string('cabin_class', 10);

            // Operating airline code, e.g. "UA". 2-3 char IATA/ICAO codes; we
            // allow a little headroom.
            $table->string('airline', 10);

            // Flight number as a string (numbers like "0101" must keep leading
            // zeros, and some carriers append letters), so we never store this
            // as an integer.
            $table->string('flight_number', 10);

            $table->timestamps();

            // A leg cannot have two segments at the same position. Guarantees
            // unambiguous positional matching and blocks duplicate inserts.
            $table->unique(['flight_leg_id', 'segment_order']);

            // The composite unique index above already covers flight_leg_id as
            // its leftmost column, so segment lookups by leg are indexed without
            // an additional standalone index.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_segments');
    }
};
