# Changelog

## 1.1.0

Added (additive, no behaviour change for existing callers):
- `service.auth`, `service.scope`, `tenant.context` middleware aliases.
- `ServicePrincipal`, `ApiKeyIntrospector`, `TenantContext`, `HasTenantReference` + `TenantScope`.
- Config: `identity.*`, `tenant.*`. Docs: `docs/SERVICE_AUTH.md`.
- Error codes: `UNAUTHENTICATED`, `APPLICATION_MISMATCH`, `SERVICE_AUTH_UNAVAILABLE`, `INSUFFICIENT_SCOPE`,
  `TENANT_CONTEXT_REQUIRED`, `TENANT_CONTEXT_INVALID`, `TENANT_APPLICATION_MISMATCH`, `TENANT_FORBIDDEN`.
- Includes the transactional outbox (ENAAA-17).

## 1.0.0

Initial release (tag `v1`).
