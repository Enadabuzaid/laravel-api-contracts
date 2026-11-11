# Connecting an app to a service

How an app (Tafadel, Sofrito, Akefak, …) plugs into a Lego service. Rules the
services follow: [SERVICE_STANDARD.md](SERVICE_STANDARD.md).

> Status: the packages below are **planned** (see SERVICE_MAP "Shared building
> blocks"). Until they exist, Sofrito's `App\Services\Identity\IdentityServiceClient`
> + `config/services.php` `identity` block is the reference consumer; the
> packages are that code extracted.

## 1. Packages

| Package | Owner | What it gives |
|---|---|---|
| `enadstack/laravel-service-client` | Integration Engineer | Abstract `ServiceClient`: base URL, bearer key, `X-Application`, `X-Tenant-Reference`, `Idempotency-Key`, timeouts, retries on connect errors, error envelope → `ServiceException`, webhook signature verifier, `fake()` plumbing |
| `enadstack/<name>-client` (e.g. `notification-client`, `loyalty-client`, `identity-client`) | Integration Engineer | Typed methods + DTOs for one service, plus a `Fake` with canned responses and assertions |

An app installs only the clients it uses:

```bash
composer require enadstack/laravel-service-client enadstack/notification-client enadstack/loyalty-client
php artisan vendor:publish --tag=service-client-config
```

## 2. Env keys

One block per service, same names in every app:

```dotenv
SERVICE_APPLICATION_CODE=tafadel          # sent as X-Application on every call

IDENTITY_SERVICE_URL=http://identity-access-service.test
IDENTITY_SERVICE_API_KEY=ia_live_xxx

NOTIFICATION_SERVICE_URL=http://notification-service.test
NOTIFICATION_SERVICE_API_KEY=ia_live_xxx  # may be the same key as identity
NOTIFICATION_WEBHOOK_SECRET=whsec_xxx

LOYALTY_SERVICE_URL=http://loyalty-service.test
LOYALTY_SERVICE_API_KEY=ia_live_xxx
LOYALTY_WEBHOOK_SECRET=whsec_xxx

# optional per service: *_SERVICE_TIMEOUT=10, *_SERVICE_CONNECT_TIMEOUT=5
```

Sofrito's current `IDENTITY_APPLICATION_CODE` maps to `SERVICE_APPLICATION_CODE`
(the client reads the old name as a fallback, so Sofrito keeps working).
An unset `*_SERVICE_URL` means "not connected": `isConfigured()` is false and
the app uses its local fallback (if any).

## 3. Service-account token

1. Services Lead / founder runs on identity-access-service:
   ```bash
   php artisan identity:service-account:create tafadel-backend \
     --application=tafadel --scope=notifications:send --scope=loyalty:earn --scope=loyalty:redeem
   ```
2. The `ia_live_…` key prints once → put it in the app's `.env` (never commit).
3. One key per app per environment by default; each service checks scopes on
   its own routes. Rotate by creating a new key, deploying, then revoking the old.
4. A key can be tenant-scoped (`--tenant=tafadel:tenant:12`); otherwise the app
   sends `X-Tenant-Reference` per request.

## 4. Making a call

```php
use Enadstack\NotificationClient\NotificationClient;
use Enadstack\NotificationClient\Data\NotificationRequest;

$receipt = app(NotificationClient::class)
    ->forTenant("tafadel:tenant:{$branch->tenant_id}")
    ->send(new NotificationRequest(
        channel: 'whatsapp',
        recipient: ['phone' => $order->customer_phone],
        templateCode: 'order_ready',
        locale: 'ar',
        variables: ['order_number' => $order->number],
        idempotencyKey: "tafadel-order-{$order->id}-ready",
    ));
// $receipt->id, $receipt->status ("queued")
```

Errors come back as `Enadstack\ServiceClient\ServiceException` with
`code()` (`VALIDATION_FAILED`, `SERVICE_UNREACHABLE`, `INSUFFICIENT_POINTS`, …),
`details()` and `status()`. Wrap calls in a queued job when the user is not
waiting; never block a latency-critical page on a service call.

## 5. Tests: use the fakes

```php
use Enadstack\NotificationClient\NotificationClient;

it('sends a WhatsApp backup when an order is ready', function () {
    $fake = NotificationClient::fake();

    markOrderReady($order);

    $fake->assertSent(fn ($r) => $r->templateCode === 'order_ready'
        && $r->channel === 'whatsapp');
});
```

No app test hits a real service. Each `<name>-client` ships a contract test
against the service's `docs/openapi.yaml`, so a fake cannot drift from the API.

## 6. Receiving webhooks

1. Register the endpoint once per app (per service; via the service API or seeder):
   `https://tafadel.test/webhooks/notification` for `notification.message.delivered`,
   `notification.message.failed`.
2. In the app, route it through the client's verifier middleware:

```php
Route::post('webhooks/notification', NotificationWebhookController::class)
    ->middleware('service-webhook:notification');   // checks X-Webhook-Signature, timestamp ≤ 5 min, dedupes X-Webhook-Id
```

3. The controller reads the standard event envelope (`type`, `version`,
   `tenant_reference`, `data`), handles it idempotently, returns `2xx` fast and
   queues the work. Non-2xx → the service retries with backoff.

## 7. Checklist for wiring a new app

- [ ] App code registered in identity `config/identity.php` applications
- [ ] Service-account key created with only the scopes needed
- [ ] Env block added to `.env.example` (no secrets)
- [ ] `<name>-client` installed; calls in jobs where the user isn't waiting
- [ ] Tests use `Client::fake()`; one test per call site
- [ ] Webhook route + secret if the app consumes events
- [ ] Local fallback decided for when `*_SERVICE_URL` is unset
