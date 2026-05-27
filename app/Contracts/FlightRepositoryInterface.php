<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Flight;

/**
 * FlightRepositoryInterface.
 *
 * Defines the persistence seam for flight updates. Consumers (the job) depend on
 * this abstraction rather than the concrete repository, so the storage strategy
 * can be swapped or faked in tests without touching call sites. This is the one
 * place an interface earns its keep here — the update path has real persistence
 * complexity (locking + positional reconciliation) worth abstracting.
 */
interface FlightRepositoryInterface
{
    /**
     * Fetch a flight by UUID with a pessimistic write lock, eager-loading its
     * ordered legs+segments. Must be called inside a transaction. Returns null
     * if no flight matches the UUID.
     */
    public function findForUpdate(string $uuid): ?Flight;

    /**
     * Apply a positional update to the (locked) flight from incoming legs.
     *
     * @param  array<int, array<string, mixed>>  $legs
     */
    public function applyPositionalUpdate(Flight $flight, array $legs): void;
}
