# Service Standard ("Lego rules")

Every reusable service in `~/Herd/*-service` must follow this page. A service
that meets it can be plugged into any app (Sofrito, Tafadel, Akefak, …) with
config only, no code changes inside the service.

Detailed design behind these rules: `docs/superpowers/specs/2026-09-02-platform-standard-design.md` (on `main`).
How an app plugs in: [CONNECTING.md](CONNECTING.md). Current state of each service: [SERVICE_MAP.md](SERVICE_MAP.md).

## 1. Package, service, or in-app?

| Question | Answer |
|---|---|
| Reusable code, no data of its own (helpers, UI, DTOs, contracts) | **Package** in `~/Herd/packages/*` |
| Own data **and** used by 2+ projects, or heavy domain (identity, notifications, payments, loyalty) | **Service** in `~/Herd/<domain>-service` |
| Used by one project only | **In the app** |
| Latency-critical, user waiting on screen (< 3 s), e.g. Tafadel "Ready → alert" page alert | **In the app**; a service may do the backup path (SMS/WhatsApp/push) |

Example: Tafadel's live "order ready" alert is a broadcast inside the app. The
backup SMS/WhatsApp goes through `notification-service`.

## 2. One domain, one database

- One service = one domain, its own database. No shared tables. Never query
  another service's DB; call its API or consume its events.
- Foreign things are stored as **references**, opaque strings
  `{application}:{type}:{id}`:
  `sofrito:tenant:42`, `tafadel:user:01JD…`, `tafadel:order:9f1c…`.
  Column names end in `_reference` (`customer_reference`, `tenant_reference`).
- `docs/SERVICE.md` lists owned tables, references held, scopes, events emitted/consumed.

## 3. API shape

- All routes under `/api/v1/...`. Controllers in `app/Http/Controllers/Api/V1`.
- Responses and errors use `enadstack/laravel-api-contracts`
  (`ApiResponses` trait + `ApiExceptionRenderer`):

```json
// single object — flat, no "data" wrapper
{"account": {"id": "01J…", "balance": 120}}
// paginated list — the only place "data" is used
{"data": [...], "links": {...}, "meta": {...}}
// error — details is always an object
{"error": {"code": "INSUFFICIENT_POINTS", "message": "Not enough points.", "details": {"required": 500}}}
```

- Error codes are `SCREAMING_SNAKE_CASE`, stable, documented in OpenAPI.
- **Additive changes only** in `v1`: new endpoints, new optional fields, new
  error codes. Renaming/removing a field or changing its type = `/api/v2`, and
  `v1` keeps working until every app has moved.
- Every API change (new endpoint, changed field) is approved by the Services
  Lead before it merges to the service's `dev`.

## 4. Multi-app, multi-tenant by default

Every request carries who is calling and for which tenant:

| Header | Example | Rule |
|---|---|---|
| `Authorization` | `Bearer ia_live_…` | service-account key issued by identity-access-service |
| `X-Application` | `tafadel` | app code; must match the key's application |
| `X-Tenant-Reference` | `tafadel:tenant:12` | required on tenant routes unless the key is tenant-scoped |
| `Idempotency-Key` | `tafadel-order-9f1c-earn` | required on every `POST`/`PATCH` that changes state |
| `X-Request-Id` | uuid | optional; echoed back and logged |

- Rows are scoped by `tenant_reference` (trait `HasTenantReference`). A query
  without tenant context fails closed.
- **Never hard-code a project name** in a service (no `if ($app === 'sofrito')`).
  Per-app behaviour comes from data (programs, templates, provider configs
  per application/tenant).

## 5. Auth between apps and services

- Only one issuer: **identity-access-service** service accounts
  (`identity:service-account:create {code} --application= --scope=*`). Key shown once.
- Services validate the key via identity `GET /api/v1/api-key/introspect`
  (cached 300 s) using contracts middleware `service.auth`, then
  `service.scope:{service}:{action}` per route, e.g.
  `loyalty:earn`, `notifications:send`.
- No service-local key tables (today notification-service and loyalty-service
  have their own; these are migrated, see SERVICE_MAP).

## 6. Writes and async results

- **Idempotent writes**: middleware `idempotency` stores the response per
  (caller, `Idempotency-Key`) for 24 h; a replay returns the same body with
  `Idempotent-Replayed: true`. Same key + different body → 422 `IDEMPOTENCY_KEY_REUSED`.
- **Events**: written to the outbox in the same DB transaction
  (`RecordOutboxEvent`), then delivered by `outbox:process`. Envelope:

```json
{
  "id": "01JD…", "type": "loyalty.points.earned", "version": 1,
  "producer": "loyalty-service", "application": "tafadel",
  "tenant_reference": "tafadel:tenant:12", "occurred_at": "2026-10-30T10:00:00Z",
  "data": {"account_id": "01J…", "points": 50, "source_reference": "tafadel:order:9f1c"}
}
```

- **Webhooks** to apps are signed: `X-Webhook-Id`, `X-Webhook-Timestamp`,
  `X-Webhook-Signature: sha256=HMAC(secret, timestamp + "." + body)`.
  Receivers reject timestamps older than 5 min and dedupe on `X-Webhook-Id`.
  Delivery retries with exponential backoff; at-least-once.
- Event names: `{domain}.{entity}.{past_tense}` (`notification.message.delivered`).
  Payload changes follow the same additive rule; breaking = `version: 2`.

## 7. What every service repo contains

| Item | Path / check |
|---|---|
| Coding rules with Laravel Boost | `CLAUDE.md`, `laravel/boost` in `composer.json` |
| Contracts package | `enadstack/laravel-api-contracts` in `composer.json` |
| OpenAPI 3.1 spec, kept in sync | `docs/openapi.yaml` |
| Pest feature test per endpoint (happy path, auth 401/403, validation 422, tenant isolation) | `tests/Feature/Api/V1/*` |
| Health route | `/up` (Laravel default), plus DB check |
| Service facts | `docs/SERVICE.md` |
| "How to connect" | README section: env keys, scopes, example call, events |

A service is **ready** when every row above is true and its endpoints use
`service.auth` + `idempotency`. **Partial** = real API exists but rows are
missing. **Empty** = no `/api/v1` routes yet (scaffold only).
