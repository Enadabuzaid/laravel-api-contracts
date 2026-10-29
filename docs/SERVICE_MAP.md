# Service Map

Audit date: 2026-10-30 (ENAAA-2). Source: `repo-orchestration/repos.yaml`, each
service's `routes/api.php`, `composer.json`, `tests/Feature`, `bootstrap/app.php`.
Standard: [SERVICE_STANDARD.md](SERVICE_STANDARD.md).

**Status:** ready = meets the standard · partial = real `/api/v1` API, gaps below · empty = scaffold, no API routes.

**Gaps common to all services (not repeated per row):** no `docs/openapi.yaml`
anywhere; no service uses identity-issued keys via `service.auth` (contracts v2
middleware is not built yet); no README "How to connect". All have `CLAUDE.md`,
Boost and `/up`, unless noted.

**Projects:** Sof = Sofrito, Taf = Tafadel, Ake = Akefak (later), Souq = Souqna.
"uses" = calls it today; "should" = planned fit.

## Founder decisions (2026-10-30)

- **A** Apps connect via one `laravel-service-client` base + one `<name>-client` per service ([CONNECTING.md](CONNECTING.md)).
- **B** Order: contracts v2 (service auth, idempotency, outbox, fakes) → identity-access → notification → loyalty.
- **C** Customer profiles and tenants stay **in-app** for Tafadel; customer-profile-service and platform-management-service come later.
- **D** `commerce-contracts` and souqna-* stay in `repos.yaml`, marked `note: not on this machine`.
- **Web push** Tafadel keeps web push (VAPID) **in-app** (free, fast step of the alert chain). notification-service covers the paid SMS/WhatsApp backup now; a push adapter is a later gap.

## First three to bring up to standard

| # | Service | Why |
|---|---|---|
| 1 | **identity-access-service** | Issuer of service-account keys; everything else depends on it. Sofrito already uses it. |
| 2 | **notification-service** | Tafadel needs the paid SMS/WhatsApp backup for "order ready" (web push stays in-app); Twilio + Meta WhatsApp adapters exist. |
| 3 | **loyalty-service** | Tafadel + Sofrito loyalty; richest domain API (30 routes) but has its own `X-API-Key` auth and only 8 feature tests. |

## All services

| Service | Status | Routes / feature tests | Gaps vs standard (beyond common) | Uses / should use |
|---|---|---|---|---|
| identity-access-service | partial | 90 / 71 | Uses contracts v1. Needs: introspect returns `application` + `tenant_reference`; `identity:service-account:create` command; no `Idempotency-Key` on writes; root `fix_*.php` scripts | uses: Sof · should: Taf, Ake, every service (auth) |
| notification-service | partial | 9 / 18 | Own `NotificationServiceAccount` bearer tokens → switch to identity keys; no contracts envelope; no idempotency; channel/tenant not persisted; no push adapter (later gap; Tafadel web push is in-app); no outbox events for delivered/failed | should: Taf (backup alerts), Sof, Ake |
| loyalty-service | partial | 30 / 8 | Own `X-API-Key` clients → identity keys; no contracts envelope; tests far below 1-per-endpoint; idempotency present on some writes (verify all) | should: Taf, Sof |
| platform-management-service | partial | 24 / 1 | No contracts; 1 feature test | should: Souq; maybe Taf/Sof for tenants/plans (decision needed) |
| items-content-service | partial | 18 / 32 | No contracts; no idempotency | should: Souq |
| merchant-catalog-service | partial | 22 / 24 | No contracts; no idempotency | should: Souq |
| inventory-service | partial | 41 / 18 | No contracts; tests below 1-per-endpoint | should: Souq |
| promotions-service | partial | 5 / 4 | No contracts; small API | should: Souq, Taf (coupons, later) |
| order-service | partial | 12 / 2 | No contracts; 2 tests | should: Souq |
| sales-service | partial | 6 / 1 | No contracts; 1 test | should: Souq |
| invoice-service | partial | 26 / 39 | No contracts | should: Souq, Sof |
| shipment-service | partial | 21 / 1 | No contracts; 1 test | should: Souq |
| search-service | partial | 8 / 5 | No contracts; no idempotency | should: Souq |
| cash-management-service | partial | 9 / 6 | No contracts; no idempotency | should: Souq POS |
| settlement-service | partial | 2 / 3 | No contracts; tiny API | should: Souq |
| purchasing-supplier-service | partial | 57 / 11 | No contracts; tests far below 1-per-endpoint | should: Souq |
| analytics-service | partial | 10 / 23 | Has contracts; own client-credential auth | uses: Sof · should: Taf |
| recommendation-service | partial | 5 / 7 | Routes **not** under `/v1`; no contracts | should: Souq |
| report-service | partial | 4 / 2 | No contracts | should: Souq, Sof |
| provider-acquisition-service | partial | 8 / 6 | No contracts | should: Ake? (decision needed) |
| payment-service | empty | 0 / 1 | No `routes/api.php`; models/migrations scaffold only | should: Taf, Sof, Souq, Ake |
| returns-rma-service | empty | 0 / 8 | Models/migrations, no API | should: Souq |
| referral-service | empty | 0 / 1 | Models/migrations, no API | should: Taf, Sof (later) |
| gift-card-service | empty | 0 / 1 | Models/migrations, no API | should: Souq |
| wallet-service | empty | 0 / 1 | Models/migrations, no API | should: Souq, Taf (later) |
| accounting-service | empty | 0 / 1 | Models/migrations, no API | should: Souq |
| customer-segmentation-service | empty | 0 / 6 | Models/migrations, no API | should: Souq, Sof |
| forecasting-service | empty | 0 / 1 | Models/migrations, no API | should: Souq |
| risk-fraud-service | empty | 0 / 1 | Models/migrations, no API | should: Souq, payment flows |
| customer-profile-service | empty | — | README + docs only, not a Laravel app, no CLAUDE.md | should: Souq, Taf, Sof (decision needed vs identity) |

## Not services (excluded from the standard)

| Repo | Note |
|---|---|
| commerce-contracts | Missing on disk; superseded by `laravel-api-contracts` (this repo). Kept in repos.yaml as `note: not on this machine`. |
| souqna-marketplace-service | Folder exists, empty. Souqna-only → app-specific, not a Lego service. |
| souqna-commerce-bff, souqna-frontends, souqna-e2e-tests, souqna-deployment | Souqna app/infra repos, not on this machine (marked in repos.yaml). |

## Shared building blocks still missing

| Item | Where | Needed by |
|---|---|---|
| Contracts v2 (`service.auth`, `service.scope`, `tenant.context`, `idempotency`, outbox, `Reference`, fakes) | `laravel-api-contracts` | every service |
| `laravel-service-client` base package | `~/Herd/packages/laravel-service-client` | every app |
| `identity-client`, `notification-client`, `loyalty-client` | `~/Herd/packages/<name>-client` | Taf, Sof |
