<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RequestHasher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the canonical request hashing used by idempotency.
 *
 * These verify the properties the idempotency contract depends on:
 *   - Reordering object KEYS does not change the hash (cosmetic difference).
 *   - Reordering legs/segments DOES change the hash (order is meaningful).
 *   - Different target flight ids produce different hashes.
 */
final class RequestHasherTest extends TestCase
{
    public function test_key_order_does_not_affect_the_hash(): void
    {
        $a = ['flightId' => 'u1', 'legs' => [['segments' => [['origin' => 'BCN', 'destination' => 'LON']]]]];
        $b = ['legs' => [['segments' => [['destination' => 'LON', 'origin' => 'BCN']]]], 'flightId' => 'u1'];

        $this->assertSame(RequestHasher::hash($a), RequestHasher::hash($b));
    }

    public function test_leg_order_affects_the_hash(): void
    {
        $a = ['legs' => [['segments' => [['origin' => 'BCN']]], ['segments' => [['origin' => 'JFK']]]]];
        $b = ['legs' => [['segments' => [['origin' => 'JFK']]], ['segments' => [['origin' => 'BCN']]]]];

        $this->assertNotSame(RequestHasher::hash($a), RequestHasher::hash($b));
    }

    public function test_flight_id_affects_the_hash(): void
    {
        $a = ['flightId' => 'u1', 'legs' => [['segments' => [['origin' => 'BCN']]]]];
        $b = ['flightId' => 'u2', 'legs' => [['segments' => [['origin' => 'BCN']]]]];

        $this->assertNotSame(RequestHasher::hash($a), RequestHasher::hash($b));
    }

    public function test_hash_is_a_sha256_hex_digest(): void
    {
        $hash = RequestHasher::hash(['flightId' => 'u1', 'legs' => []]);

        $this->assertSame(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }
}
