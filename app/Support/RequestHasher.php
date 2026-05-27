<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RequestHasher.
 *
 * Produces a stable, canonical hash of an update payload so we can detect when a
 * client reuses the SAME Idempotency-Key with a DIFFERENT body — which is a
 * client error (the idempotency contract is "same key => same request").
 *
 * Canonicalization matters: two JSON bodies that are semantically identical but
 * differ in key ordering or whitespace must hash to the same value. We achieve
 * this by recursively sorting array keys before encoding.
 */
final class RequestHasher
{
    /**
     * Hash the given payload deterministically.
     *
     * @param  array<mixed>  $payload
     * @return string  A 64-char SHA-256 hex digest (fits the request_hash column).
     */
    public static function hash(array $payload): string
    {
        $canonical = self::canonicalize($payload);

        // JSON-encode the canonical structure. Flags keep the encoding stable
        // and lossless (no unicode escaping surprises, slashes left intact).
        $json = json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        // SHA-256 yields a fixed 64-char hex string matching the DB column size.
        return hash('sha256', (string) $json);
    }

    /**
     * Recursively sort array keys so logically-equivalent payloads canonicalize
     * identically regardless of the order keys arrived in.
     *
     * Associative arrays are sorted by key; list arrays preserve their order
     * (order is meaningful for legs/segments), but their nested elements are
     * still canonicalized.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        // Determine if this is a list (sequential 0..n keys) or an associative
        // map. Lists keep their order; maps get key-sorted.
        $isList = array_is_list($value);

        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::canonicalize($item);
        }

        if (! $isList) {
            // Sort associative arrays by key for a canonical form.
            ksort($result);
        }

        return $result;
    }
}
