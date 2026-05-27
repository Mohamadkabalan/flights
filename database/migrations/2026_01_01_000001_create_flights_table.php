<?php

// Strict types ensures PHP enforces type declarations rigorously,
// catching type-coercion bugs at runtime instead of silently converting values.
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Anonymous migration class (Laravel 9+ style). Using an anonymous class avoids
// class-name collisions between migrations and is the modern Laravel convention.
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the `flights` table, which is the aggregate root of our domain.
     * A Flight owns one or more Legs, and each Leg owns one or more Segments.
     */
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            // Auto-incrementing internal primary key. We keep a numeric PK for
            // efficient internal joins/foreign keys while exposing a UUID publicly.
            $table->id();

            // Public-facing identifier. We never expose the auto-increment `id`
            // to clients; instead we use this UUID in URLs and API responses.
            // It is unique so it can be safely used for lookups.
            $table->uuid('uuid')->unique();

            // Standard Laravel created_at / updated_at timestamp columns.
            $table->timestamps();

            // Explicit index on `uuid`. Although `unique()` already creates an
            // index, we declare intent clearly: every Get/Update request looks a
            // flight up by its UUID, so this is the hottest lookup path.
            // (The unique constraint covers this; kept here only as documentation
            //  of the lookup pattern — Laravel will not create a duplicate index
            //  because we rely on the unique index above. So we do NOT add it twice.)
        });
    }

    /**
     * Reverse the migrations.
     *
     * Drops the table if it exists. Used when rolling back during development
     * or in CI when resetting the schema.
     */
    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
