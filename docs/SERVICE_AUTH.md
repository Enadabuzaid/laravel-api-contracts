# Service auth, scopes and tenant context

Implements SERVICE_STANDARD §4 and §5. Aliases are registered by the package service provider.

## Middleware order

`service.auth` -> `service.scope:...` -> `tenant.context` -> `idempotency`

## service.auth

Reads `Authorization: Bearer <key>` and `X-Application`, calls identity-access-service
`GET {identity.base_url}{identity.path}` with the key and expects
`{active, scopes[], expires_at, application, tenant_reference, owner, api_key_id}`.

| Situation | Response |
|---|---|
| no bearer, identity says 401/403/404, `active:false`, `expires_at` in the past | 401 `UNAUTHENTICATED` |
| key application != `X-Application` (or header missing) | 403 `APPLICATION_MISMATCH` |
| identity unreachable, timeout, 5xx, unusable body, `base_url` unset | 503 `SERVICE_AUTH_UNAVAILABLE` (never allowed) |

Only successful introspections are cached, under `api-contracts:introspect:<sha256(key)>`, for
`min(identity.cache_ttl, time to expires_at)` seconds. The raw key is never cached or logged.
Revocation is therefore visible after at most `cache_ttl` seconds. The standard's "300 s" is a ceiling;
the default here is 60 s. The principal is available as `ServicePrincipal::from($request)`.

## service.scope

`service.scope:loyalty:earn,loyalty:read` requires all listed scopes. `*` grants everything,
`loyalty:*` grants every `loyalty:` scope. Failure: 403 `INSUFFICIENT_SCOPE`, `details.missing` lists them.
Without `service.auth` before it: 401.

## tenant.context

- `X-Tenant-Reference` must match `tenant.pattern` (named groups `prefix`, `id`; default `<prefix>:tenant:<id>`).
- Missing -> 400 `TENANT_CONTEXT_REQUIRED` (unless the key is pinned to a tenant, then the pinned one is used,
  per §4). Malformed -> 400 `TENANT_CONTEXT_INVALID`.
- Prefix != application -> 403 `TENANT_APPLICATION_MISMATCH`.
- Key pinned to another tenant -> 403 `TENANT_FORBIDDEN`.
- Sets the scoped `TenantContext` (cleared after the response).

## HasTenantReference

Add `use HasTenantReference;` to models having `tenant_reference` and `application` string columns.
Reads add `WHERE tenant_reference = ? AND application = ?`; with no context they throw
`MissingTenantContextException`. Creates fill both columns and refuse a conflicting value.
Explicit opt-out: `Model::withoutGlobalScope(TenantScope::class)`.
Queue jobs/console/tests: `TenantContext::within('sofrito:tenant:1', 'sofrito', fn () => ...)`.

### Event-bypass gap

`HasTenantReference` fills and checks `tenant_reference` and `application` in its `creating` model event. Any write
that skips model events skips that hook, so the tenant guard does not run for it. This covers:

- `saveQuietly()` and `withoutEvents(...)`
- the `WithoutModelEvents` trait (seeders, factories, tests)
- `insert()` and other query-builder writes, which never raise model events

The global read scope still applies to reads, but a bypassed write can store a `tenant_reference` or `application`
that does not match the active `TenantContext`. Consuming services must not use these paths for tenant models.
Their own tests or CI should enforce that (for example with an architecture test). Preferring
`Model::create()` / `save()` inside `TenantContext::within(...)` is the supported way to write.

This is a documentation note only. The package does not change its behaviour, and no code enforces it here.

## Config

| Key | Default |
|---|---|
| `identity.base_url` | `env('IDENTITY_BASE_URL')` |
| `identity.path` | `/api/v1/api-key/introspect` |
| `identity.timeout` | `3` |
| `identity.cache_ttl` | `60` |
| `identity.cache_store` | `null` (default store) |
| `tenant.header` | `X-Tenant-Reference` |
| `tenant.pattern` | `/^(?<prefix>[a-z][a-z0-9_-]*):tenant:(?<id>[A-Za-z0-9_-]+)$/` |
