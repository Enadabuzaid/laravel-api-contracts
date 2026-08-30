# laravel-api-contracts

Shared versioned commerce contracts for event schemas, API conventions, money DTOs, reference formats, error envelopes, pagination, and integration standards — consumed by the independent Laravel microservices in this workspace.

## Implementation Status

IN PROGRESS — `Enadstack\ApiContracts\Http\Responses\ApiResponses` (success/error/pagination response trait) and `Enadstack\ApiContracts\Http\Exceptions\ApiExceptionRenderer` (global exception normalization) are implemented and consumed by `identity-access-service` as the reference integration.

## API Response Conventions

- **Success with data** stays flat — no `data` wrapper — so each service's contracted field names (e.g. `user`, `session`) live at the top level: `{"user": {...}, "session": {...}}`.
- **Success, message only**: `{"message": "..."}`.
- **Error**: `{"error": {"code": "SCREAMING_SNAKE_CASE", "message": "...", "details": {}}}` — `details` is always a JSON object, never an array, even when empty.
- **Paginated success** is the one place `data` is used as a wrapper key, matching Laravel's own default paginated-resource shape: `{"data": [...], "links": {...}, "meta": {...}}`.

This means `data` is reserved specifically for array/paginated collections; single-object success responses stay flat. Don't "fix" this into wrapping everything in `data`.

## Installation (local path repository)

In a consuming service's `composer.json`:

```json
{
    "repositories": [
        { "type": "path", "url": "../laravel-api-contracts" }
    ],
    "require": {
        "enadstack/laravel-api-contracts": "*"
    }
}
```

Then:

```bash
composer require enadstack/laravel-api-contracts:*
php artisan vendor:publish --tag=api-contracts-config
```

In `bootstrap/app.php`, inside `->withExceptions()`:

```php
use Enadstack\ApiContracts\Http\Exceptions\ApiExceptionRenderer;

->withExceptions(function (Exceptions $exceptions) {
    ApiExceptionRenderer::register($exceptions);
})
```

And in the service's base `app/Http/Controllers/Controller.php`:

```php
use Enadstack\ApiContracts\Http\Responses\ApiResponses;

abstract class Controller
{
    use ApiResponses;
}
```
