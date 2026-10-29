<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Error Codes
    |--------------------------------------------------------------------------
    |
    | Machine-readable codes used by ApiExceptionRenderer for exceptions the
    | framework throws before a controller can build a specific response
    | (e.g. failed validation, missing/invalid auth, unknown routes).
    | Override per-service to match that service's own error contract.
    */
    'error' => [
        'validation_code' => 'VALIDATION_ERROR',
        'unauthenticated_code' => 'UNAUTHORIZED',
        'unauthorized_code' => 'FORBIDDEN',
        'not_found_code' => 'NOT_FOUND',
        'method_not_allowed_code' => 'METHOD_NOT_ALLOWED',
        'rate_limited_code' => 'RATE_LIMITED',
        'server_error_code' => 'SERVER_ERROR',
    ],

    /*
    | Idempotency: how long a stored response is replayed (seconds).
    */
    'idempotency' => [
        'ttl' => 86400,
    ],

    /*
    | Webhooks: max accepted clock skew for signed deliveries (seconds).
    */
    'webhooks' => [
        'tolerance' => 300,
    ],

    /*
    | Outbox (SERVICE_STANDARD §6). Subscriptions are webhook targets:
    | ['url' => ..., 'secret' => ..., 'events' => ['loyalty.*']] ('*' = all).
    | Backoff is base * 2^(attempt-1) seconds, capped at backoff_max.
    */
    'outbox' => [
        'subscriptions' => [],
        'max_attempts' => 8,
        'backoff_base' => 30,
        'backoff_max' => 3600,
        'batch' => 100,
        'timeout' => 10,
    ],

];
