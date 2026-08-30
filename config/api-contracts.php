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

];
