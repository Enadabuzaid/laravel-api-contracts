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

## Idempotency, webhooks and events (SERVICE_STANDARD §6)

**Idempotency middleware** — publish the table, then add `idempotency` to write routes:

```bash
php artisan vendor:publish --tag=api-contracts-migrations && php artisan migrate
```

```php
Route::middleware(['service.auth', 'idempotency'])->post('/v1/points/earn', ...);
```

A request with an `Idempotency-Key` header is stored per (caller, key) for 24 h
(`api-contracts.idempotency.ttl`). A replay returns the stored body with
`Idempotent-Replayed: true`; same key with a different body returns 422
`IDEMPOTENCY_KEY_REUSED`; a concurrent duplicate returns 409 `IDEMPOTENCY_KEY_IN_PROGRESS`.
5xx responses are not stored.

**Webhooks**

```php
$headers = (new WebhookSigner)->headers($secret, $body);   // X-Webhook-Id/Timestamp/Signature
(new WebhookVerifier)->verify($secret, $body, $request->headers->all()); // throws InvalidWebhookException; rejects > 5 min
```

**Event envelope**

```php
$event = EventEnvelope::make('loyalty.points.earned', 'loyalty-service', 'tafadel', 'tafadel:tenant:12', ['points' => 50]);
json_encode($event);                     // {id,type,version,producer,application,tenant_reference,occurred_at,data}
EventEnvelope::fromArray($payload);      // consumer side
```

**Transactional outbox** — events commit with your data, a scheduled command delivers them:

```php
DB::transaction(function () use ($order) {
    $order->save();
    app(RecordOutboxEvent::class)->handle(EventEnvelope::make('order.order.placed', 'order-service', $app, $tenant, [...]));
});
```

```php
// config/api-contracts.php → outbox.subscriptions
['url' => 'https://app.test/hooks', 'secret' => '…', 'events' => ['order.*']]
```

Publish the migration (`api-contracts-migrations`) and schedule `outbox:process`
every minute. Each delivery is a signed webhook (`X-Webhook-Id` = event id). Failures
retry with backoff `base * 2^(attempt-1)` (`backoff_base` 30 s, `backoff_max` 3600 s) until
`max_attempts` (8), then the row is `failed`. Delivery is at-least-once; receivers dedupe on `X-Webhook-Id`.
