# Platform Standard for Reusable Services — Design

Date: 2026-09-02
Status: Approved (design), pending implementation plan
Scope owner: Enad Abuzaid
Home: `enadstack/laravel-api-contracts` (this repo is the standard's source of truth)

## 1. Why this exists

The Herd workspace holds one long-term vision: a set of reusable Laravel
services (identity, notification, reviews, analytics, and a 29-service
commerce platform) consumed by independent products (Sofrito, TabibiHub,
MuhamiHub, and the Souqna e-commerce platform).

A survey on 2026-09-02 found that the services were built in three
different lineages with no written standard:

| Lineage | Services | Tenancy | Inbound auth | State |
|---|---|---|---|---|
| Local tenant tables | merchant-catalog, inventory, invoice, loyalty, cash-management, settlement | integer `tenant_id`/`merchant_id` + `BelongsToMerchant` scope | `X-API-Key` → local `ApiClient` or Sanctum | Healthy suites (catalog 264, inventory 262, invoice 186 green); loyalty red |
| Generated skeletons | wallet, accounting, forecasting, risk-fraud, report, gift-card, referral, returns-rma, segmentation, platform-management, payment, sales | string `*_reference` columns | none, or unsigned headers | Schema + models only; most have no API routes; 33 `fix_*.php` migration-rewrite scripts across the workspace |
| Contracts-based | identity-access, review-rating, analytics | string `*_reference` | identity `ia_live_` keys / shared secret / client credentials | Working, but three different auth schemes |

Consequences: only Sofrito integrates any service; TabibiHub and MuhamiHub
re-implement roles, audit logs, FAQ, notifications and search locally while
the packages for those (`laravel-roles`, `laravel-action-logger`, `cms-faq`,
`approvio`) have zero consumers; no service validates callers against
identity; every service `CLAUDE.md` is Laravel Boost boilerplate with no
architecture record.

Decisions taken during brainstorming:

1. **First driver: the SaaS trio** (Sofrito, TabibiHub, MuhamiHub). The
   Souqna commerce services are parked with an adoption checklist.
2. **Identity depth: same as Sofrito.** Login delegated to identity for the
   app's tenant/admin users, sync-on-create, one-time migration, roles stay
   local. No cross-product SSO in this phase.
3. **Approach A.** One contracts package as the standard, one client SDK
   package, identity as the credential authority. No per-service shared
   secrets, no API gateway.

## 2. Goals and non-goals

Goals

- One documented standard every service and app follows, enforced by
  package code rather than by memory.
- Any product can call any in-scope service with one credential type,
  issued and revoked in identity.
- TabibiHub and MuhamiHub are wired to identity and notification the same
  way Sofrito is, using shared client code instead of copies.
- Every in-scope test suite is green, and each service has a test proving
  its auth boundary.

Non-goals (this phase)

- Cross-product single sign-on, or moving roles/permissions out of the apps.
- A message broker. Outbox delivery is HTTP to subscribers.
- Converting the Souqna commerce services. They get a checklist only. The
  single exception is making their currently red suites green (loyalty's
  ULID/bigint foreign key, cash-management's aborted-transaction tests) so
  the whole board reads honestly; no architectural change there.
- Replacing TabibiHub/MuhamiHub local audit-log and FAQ code (data
  migration; later phase).
- Deleting `fix_*.php` scripts in parked services. They are removed when a
  service is next touched.

## 3. Architecture

```
 Sofrito / TabibiHub / MuhamiHub (Laravel apps)
        │  enadstack/laravel-service-clients
        │  Authorization: Bearer ia_live_…   X-Application: <app>
        ▼
 ┌──────────────────────┐   introspect   ┌───────────────────────┐
 │ notification-service │──────────────▶ │ identity-access-service│
 │ review-rating-service│                │  applications          │
 │ analytics-service    │                │  service_accounts+keys │
 └──────────────────────┘                │  users/sessions        │
        ▲                                └───────────────────────┘
        │  enadstack/laravel-api-contracts (middleware, references, outbox)
```

Three layers:

1. **`enadstack/laravel-api-contracts` v2** — what a *service* installs.
   Response envelope (existing), references, service authentication, tenant
   context, outbox, idempotency, test fakes.
2. **`enadstack/laravel-service-clients`** — what an *app* installs. Typed
   HTTP clients per service with fakes.
3. **identity-access-service** — issues and introspects service keys, owns
   users and sessions, knows the list of applications.

### 3.1 Vocabulary

- **Application**: a product (`sofrito`, `tabibihub`, `muhamihub`, `souqna`).
  Registered in identity's `config/identity.php` applications list.
- **Reference**: an opaque string `{application}:{type}:{id}`, e.g.
  `sofrito:tenant:42`, `tabibihub:user:01J…`. Services store references and
  never own the referenced table. Types used in this phase: `tenant`,
  `user`, `merchant`, `branch`.
- **Service account**: an identity row representing a calling system, with
  scopes and an owning application. Keys (`ia_live_…`) hang off it.
- **Scope**: `{service}:{action}` string, e.g. `notifications:send`,
  `reviews:moderate`, `identity:import_users`.

### 3.2 Request flow (app → service)

1. App builds a request through the SDK: base URL from config, bearer key
   from config, `X-Application` header, JSON, timeouts.
2. Service's `AuthenticateServiceKey` middleware reads the bearer token. If
   it does not start with `ia_live_`, 401 `UNAUTHORIZED`.
3. Middleware looks up cache key `svc-auth:{sha256(token)}`. On miss it
   calls identity `GET /api/v1/api-key/introspect` with the same bearer
   token, caches the JSON for `api-contracts.service_auth.cache_ttl`
   (default 300 s), and on identity connection failure returns 503
   `IDENTITY_UNAVAILABLE`. Introspect responses with `active: false` are
   cached for 60 s and yield 401.
4. Middleware sets a `ServiceCaller` object on the request and in Laravel
   `Context`: `service_code`, `application`, `scopes[]`,
   `tenant_reference|null`.
5. `RequireScope:notifications:send` checks `ServiceCaller::hasScope`.
   Missing scope → 403 `FORBIDDEN` with `details.required_scope`.
6. `ResolveTenantContext` sets the tenant reference: from the caller's key
   if the key is tenant-scoped; otherwise from `X-Tenant-Reference`, which
   must parse as a `Reference` whose application equals the caller's
   application, else 422 `INVALID_TENANT_REFERENCE`. Routes that are
   platform-wide (no tenant) opt out by not applying the middleware.
7. Controller runs. Models using `HasTenantReference` are automatically
   scoped to `Context::get('tenant_reference')` and fill it on create.

### 3.3 Login flow (app user → identity), unchanged from Sofrito

1. App's Fortify `authenticateUsing` hook calls
   `IdentityClient::login(email, password)` with the app's service key and
   `application` code.
2. On success the app finds the local user by `identity_public_id` (or by
   email for a not-yet-linked user, then stores the public id), stores the
   identity session token in the Laravel session, and returns the user.
3. On identity error code (`INVALID_CREDENTIALS`, `MEMBERSHIP_SUSPENDED`,
   …) the app fails login with that message. On `SERVICE_UNREACHABLE` the
   app fails login with a "try again" message. There is no local password
   fallback (hard cutover), matching Sofrito's tenant guard.
4. Logout revokes the identity session best-effort.
5. Per-request verification of the identity session is not done; the local
   session guard gates pages. This is the accepted limitation Sofrito
   already documents.

## 4. Components

### 4.1 `enadstack/laravel-api-contracts` v2

Namespace `Enadstack\ApiContracts`. Requires `illuminate/* ^12.0|^13.0`
(TabibiHub is on Laravel 12). Tested with Orchestra Testbench.

| Unit | Public surface | Depends on |
|---|---|---|
| `Support\Reference` | `Reference::make(app, type, id)`, `Reference::parse(string)`, `->application()`, `->type()`, `->id()`, `(string)`; `Rules\ReferenceRule` (optional expected type/app) | nothing |
| `Auth\ServiceCaller` | readonly: `serviceCode`, `application`, `scopes`, `tenantReference`; `hasScope(string)` | Reference |
| `Auth\Introspector` (interface) + `Auth\HttpIntrospector` | `introspect(string $token): ?ServiceCaller` | Laravel HTTP client, cache |
| `Http\Middleware\AuthenticateServiceKey` | alias `service.auth` | Introspector |
| `Http\Middleware\RequireScope` | alias `service.scope:{scope}` | ServiceCaller |
| `Http\Middleware\ResolveTenantContext` | alias `tenant.context` | ServiceCaller, Reference, `Context` |
| `Http\Middleware\Idempotency` | alias `idempotency`; stores response by (`caller`, `Idempotency-Key`) in cache for 24 h; replays with `Idempotent-Replayed: true` | cache |
| `Models\Concerns\HasTenantReference` | global scope on `tenant_reference` from `Context`; fills on create; `withoutTenantScope()` | Context |
| `Outbox\OutboxEvent` model + migration stub (publishable) | columns: `id` uuid, `event_type`, `event_version`, `producer`, `tenant_reference`, `aggregate_type`, `aggregate_id`, `payload` json, `occurred_at`, `published_at`, `attempts`, `last_error` | — |
| `Outbox\RecordOutboxEvent` | `__invoke(type, aggregate, payload, version=1)` inside the caller's transaction | OutboxEvent |
| `Outbox\Publisher` (interface) + `Outbox\HttpPublisher` | posts the envelope to subscriber URLs from `api-contracts.outbox.subscribers[event_type][]` with a `X-Signature` HMAC | HTTP client |
| `Console\ProcessOutboxCommand` | `outbox:process --limit=100`; exponential backoff on `attempts` | Publisher |
| `Testing\ServiceAuth` | `ServiceAuth::fake(serviceCode, application, scopes, tenantReference)` binds a fake Introspector; `ServiceAuth::actingAsService(...)` returns request headers | Introspector |
| `Exceptions\ServiceUnavailableException` | implements `HasErrorCode`, 503 | existing |

Config `config/api-contracts.php` gains:

```php
'service_auth' => [
    'identity_url'   => env('IDENTITY_SERVICE_URL'),
    'cache_store'    => env('SERVICE_AUTH_CACHE_STORE'),   // null = default
    'cache_ttl'      => 300,
    'negative_ttl'   => 60,
    'timeout'        => 5,
],
'outbox' => [
    'subscribers'    => [],   // 'notification.sent' => ['https://…']
    'signing_secret' => env('OUTBOX_SIGNING_SECRET'),
],
```

Existing behaviour kept as-is: `ApiResponses`, `ApiExceptionRenderer`,
`HasErrorCode`, flat success / `data`-wrapped pagination / `error` envelope
with `details` always an object.

`STANDARD.md` at the repo root documents all of the above in one page plus
the folder layout every service uses:
`Http/Controllers/Api/V1`, `Http/Requests`, `Actions` (write paths),
`Services` (read/query and orchestration), `Models`, `Enums`,
`Console/Commands`, `docs/SERVICE.md` (owned tables, not-owned references,
scopes, events emitted/consumed).

### 4.2 `enadstack/laravel-service-clients`

Namespace `Enadstack\ServiceClients`. Requires `illuminate/* ^12.0|^13.0`.

| Unit | Public surface |
|---|---|
| `ServiceClient` (abstract) | `request(): PendingRequest` (baseUrl, bearer key, `X-Application`, JSON, timeouts); `send(method, path, payload)`; converts `{"error":…}` to `ServiceException(code, message, details, status)`; `ConnectionException` → `ServiceException('SERVICE_UNREACHABLE')`; `isConfigured()` |
| `ServiceException` | `code()`, `details()`, `status()`; implements `HasErrorCode` so an app can rethrow it through the renderer |
| `Identity\IdentityClient` | Sofrito's `IdentityServiceClient` moved verbatim: `register`, `login`, `me`, `logout`, `sendEmailVerification`, `verifyEmail`, `updateProfile`, `forgotPassword`, plus `importUser(payload)` (wraps `/internal/users/import`) |
| `Notification\NotificationClient` | `send(NotificationRequest)`: channel, recipient (email/phone/user reference), templateCode, locale, variables, tenantReference, idempotencyKey → `NotificationReceipt(id, status)` |
| `Review\ReviewClient` | Sofrito's `ReviewServiceClient` moved verbatim, header swapped to bearer key |
| `Analytics\AnalyticsClient` | Sofrito's analytics calls moved; keeps analytics' current client-credential header until that service adopts the standard |
| `Testing\{Identity,Notification,Review,Analytics}Fake` | `IdentityClient::fake()` swaps the container binding; records calls; `assertSent(fn)`; canned responses per method |

Config `config/service-clients.php`:

```php
'application' => env('SERVICE_APPLICATION_CODE'),        // 'tabibihub'
'identity'     => ['url' => env('IDENTITY_SERVICE_URL'), 'api_key' => env('IDENTITY_SERVICE_API_KEY'), 'timeout' => 10, 'connect_timeout' => 5],
'notification' => ['url' => env('NOTIFICATION_SERVICE_URL'), 'api_key' => env('NOTIFICATION_SERVICE_API_KEY'), …],
'reviews'      => [...],
'analytics'    => [...],
```

One identity-issued key per app per environment is the default: the same
`ia_live_` key is sent to every service, and each service checks scopes.
Per-service keys remain possible by setting different values.

### 4.3 identity-access-service changes

- `config/identity.php` applications: add `tabibihub`, `muhamihub`.
- `service_accounts.application_id` (nullable FK to `applications`);
  introspect response adds `"application": "<code>|null"` and
  `"tenant_reference"`.
- Artisan `identity:service-account:create {service_code} {--application=}
  {--scope=*} {--tenant=}` creates the account and one key and prints the
  key once. Replaces manual DB inserts.
- Restore the `roles` routes in `routes/api.php` (controller exists, tests
  expect them).
- Ensure `ServiceCredentialGateTest` and `RoleApiTest` are green after the
  `b8c90c8` gate commit; fix whichever side is wrong.
- Delete the five `fix_*.php` scripts from the repo root (this service is
  in scope, so it is cleaned now).

### 4.4 notification-service changes

- Install contracts v2. `api` group: `service.auth`, `tenant.context`.
  `POST /v1/notifications` requires `service.scope:notifications:send`.
  Webhook receivers stay public but keep provider signature checks.
- `notifications` gains `application_reference`, `tenant_reference`
  (from context) and the request's `channel` is persisted (today it is
  validated and dropped). Category and priority accept optional request
  values with the current defaults.
- `POST /v1/templates` and `PUT /v1/templates/{code}` behind
  `service.scope:notifications:templates.manage`, scoped by application:
  creates template + version + channel rows for the caller's application.
- Delete `CheckApiScope` (mock token) and the four `fix_*.php` scripts.
- Provider configs: a seeder that creates an SMTP provider config from
  `MAIL_*` env for local/dev so the welcome-email path works on Herd.
- Emit `notification.sent` / `notification.failed` outbox events.

### 4.5 review-rating-service and analytics-service

- review-rating: replace `VerifyServiceToken` with `service.auth` +
  `service.scope:reviews:*`; `X-Moderator-Reference` becomes a validated
  `Reference`. Delete `fix_migrations.php`.
- analytics: no auth change this phase (76 green tests, working client
  credentials). Only bump to contracts v2 for the shared `Reference` rule.

### 4.6 Apps

Sofrito

- Replace `App\Services\Identity\IdentityServiceClient` and
  `App\Services\Reviews\ReviewServiceClient` with the SDK classes via a
  container alias, keeping `ClientIdentityService` and callers untouched.
- Existing `Http::fake` tests keep passing; add one test per client that
  the SDK fake records the call.
- `config/services.php` identity/reviews blocks replaced by
  `config/service-clients.php`.

TabibiHub and MuhamiHub (identical shape)

- `users.identity_public_id` (uuid, nullable, unique).
- Fortify `authenticateUsing` → `IdentityClient::login`; link by public id
  or email; hard cutover; failure messages mapped from error codes.
- `CreateNewUser` and every admin user-creation path call
  `IdentityClient::register` first and store the public id; on identity
  failure the local creation is aborted with the error (no silent null),
  because these apps have no existing sync-on-create behaviour to preserve.
- Logout revokes the identity session best-effort.
- `app:migrate-users-to-identity` command posts existing bcrypt hashes with
  `application` and `tenant_reference` (`tabibihub:tenant:{ulid}` /
  `muhamihub:tenant:{id}`) to `importUser`; idempotent, resumable, dry-run.
- Welcome / invitation email sent through `NotificationClient` with a
  template registered by the app on first deploy (`notifications:setup`
  artisan command in each app using the templates endpoint). The local
  Laravel notification stays as a fallback only when
  `NOTIFICATION_SERVICE_URL` is unset.
- Roles stay local (spatie in TabibiHub, `UserRole` enum in MuhamiHub).

Packages

- `laravel-roles`: constraint to `illuminate/support ^12.0|^13.0`, delete
  the 56 root markdown files into `docs/archive/`, tag. Adoption deferred.
- `cms-faq`: same constraint bump; put under git. Adoption deferred.
- `provider-acquisition-service`: `git init`, initial commit, push, add to
  `repo-orchestration/repos.yaml`. No code changes.

## 5. Error handling

| Situation | Behaviour |
|---|---|
| Service receives no/invalid bearer | 401 `UNAUTHORIZED` |
| Key valid, scope missing | 403 `FORBIDDEN`, `details.required_scope` |
| Identity unreachable during introspect (cache miss) | 503 `IDENTITY_UNAVAILABLE`; cached positive results keep working for their TTL |
| Tenant reference missing/foreign application | 422 `INVALID_TENANT_REFERENCE` |
| App cannot reach a service | SDK throws `ServiceException('SERVICE_UNREACHABLE')`; login shows "try again"; notification send is queued for retry by the app's job, not lost |
| Idempotency-Key replay | Original response returned, `Idempotent-Replayed: true` |
| Outbox delivery fails | `attempts++`, `last_error`, backoff `min(2^attempts, 60) minutes`; alert after 10 |

## 6. Testing

- Contracts: Testbench tests per unit (reference parse/format/rule,
  middleware matrix: no token / bad prefix / inactive / missing scope /
  identity down / tenant mismatch, idempotency replay, outbox record +
  process with a fake publisher).
- SDK: Testbench tests with `Http::fake` for each client method's success,
  error envelope, and connection failure; fake-recorder assertions.
- Identity: existing suite green plus tests for the new command and the
  `application` field in introspect.
- Each service in scope: `tests/Feature/ServiceAuthTest` (401/403/tenant
  matrix using `ServiceAuth::fake()`), plus its existing suite green.
  Loyalty's ULID/bigint FK and cash-management's aborted-transaction tests
  are fixed in the same pass so the in-scope board is fully green.
- Apps: login delegation tests (success, invalid credentials, suspended,
  unreachable), sync-on-create tests, migration command tests, notification
  send test, all on SDK fakes. Sofrito's 147-file suite stays green.
- End-to-end: `repo-orchestration/scripts/smoke.sh` against the Herd
  `.test` domains: create a service account, introspect, send a
  notification, register + login a user for `tabibihub`. Run manually
  before tagging.

## 7. Phasing (implementation plan follows this order)

1. Green board: identity failing tests, loyalty FK, cash-management
   transaction tests, provider-acquisition under git.
2. Contracts v2 with `STANDARD.md`, proven on review-rating.
3. Identity: applications, service-account command, introspect fields.
4. SDK package; Sofrito migrated with no behaviour change.
5. Notification-service on the standard, templates endpoint, dev SMTP.
6. TabibiHub wired (identity + notification).
7. MuhamiHub wired (identity + notification).
8. Package hygiene (`laravel-roles`, `cms-faq`), adoption checklist for the
   parked commerce services, smoke script, `CLAUDE.md` pointer sections.

## 8. Acceptance criteria

- `STANDARD.md` exists and every in-scope repo's `CLAUDE.md` links to it.
- Contracts v2 and SDK tagged; both installed via GitHub VCS in every
  in-scope repo; no `path` repositories.
- No in-scope service trusts an unauthenticated tenant header; every
  in-scope service rejects a request without a valid `ia_live_` key.
- `identity:service-account:create` produces a key that works against
  notification-service and review-rating-service without manual DB edits.
- TabibiHub and MuhamiHub users log in through identity, new users are
  created in identity first, and the migration command imports existing
  users idempotently.
- One welcome email per app is delivered via notification-service on Herd.
- All suites in scope are green: contracts, SDK, identity, notification,
  review-rating, analytics, loyalty, cash-management, Sofrito, TabibiHub,
  MuhamiHub.
- No `fix_*.php` scripts remain in in-scope services.
