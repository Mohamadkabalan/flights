<?php

declare(strict_types=1);

/**
 * API authentication configuration.
 *
 * Keeps the shared-secret API key out of code and in the environment. The
 * EnsureApiKeyIsValid middleware reads `auth-api.key` to validate the incoming
 * `Api-Key` header.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    |
    | The secret value clients must send in the `Api-Key` header. Set this via
    | the API_KEY environment variable. There is no default in production-like
    | environments — leaving it unset causes the middleware to fail closed
    | (reject all requests) rather than allow unauthenticated access.
    |
    */

    'key' => env('API_KEY'),

];
