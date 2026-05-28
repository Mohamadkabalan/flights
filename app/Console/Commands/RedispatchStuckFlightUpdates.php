<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\UpdateFlightJob;
use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * RedispatchStuckFlightUpdates.
 *
 * Recovery net for the rare case where an idempotency row committed but its
 * UpdateFlightJob never reached a worker (queue backend lost the message after
 * commit). Such a row sits in PENDING indefinitely; nothing else reclaims it,
 * since staleLocks only targets PROCESSING.
 *
 * This command finds PENDING rows older than a threshold and re-dispatches their
 * jobs from the persisted request_payload. Re-dispatch is SAFE: the job's
 * IdempotencyManager::claim() admits exactly one runner and skips rows that are
 * completed or actively locked, so a row whose job actually ran (and is thus no
 * longer PENDING) is never selected, and a genuine double-dispatch still results
 * in a single applied update.
 *
 * Schedule it in routes/console.php (every five minutes is reasonable).
 */
final class RedispatchStuckFlightUpdates extends Command
{
    /**
     * @var string
     */
    protected $signature = 'flights:redispatch-stuck {--age=300 : Minimum row age in seconds before re-dispatch}';

    /**
     * @var string
     */
    protected $description = 'Re-dispatch flight update jobs for PENDING idempotency rows whose job was lost.';

    public function handle(): int
    {
        $age = (int) $this->option('age');
        $count = 0;

        IdempotencyKey::query()
          ->stalePending($age)
          ->where('operation', IdempotencyKey::OPERATION_UPDATE_FLIGHT)
          ->whereNotNull('flight_id')
          ->whereNotNull('request_payload')
          ->each(function (IdempotencyKey $record) use (&$count): void {
              $legs = $this->legsFor($record);

              // Without a payload we cannot reconstruct the work; skip and warn
              // so an operator can investigate rather than silently dropping it.
              if ($legs === []) {
                  $this->warn("Skipping key {$record->key}: empty/missing payload.");

                  return;
              }

              UpdateFlightJob::dispatch(
                flightUuid: (string) $record->flight_id,
                legs: $legs,
                idempotencyKeyId: $record->id,
              );

              $count++;
              $this->info("Re-dispatched update for key {$record->key}.");
          });

        $this->info("Re-dispatched {$count} stuck flight update(s).");

        return self::SUCCESS;
    }

    /**
     * Extract the legs array from a record's persisted request payload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function legsFor(IdempotencyKey $record): array
    {
        $payload = $record->request_payload;

        if (! is_array($payload) || ! isset($payload['legs']) || ! is_array($payload['legs'])) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $legs */
        $legs = $payload['legs'];

        return $legs;
    }
}