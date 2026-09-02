# Platform Standard — Roadmap and Plan Index

**Spec:** `docs/superpowers/specs/2026-09-02-platform-standard-design.md`

The spec is implemented as a series of plans. Each plan produces working,
tested software on its own and is executed with
`superpowers:subagent-driven-development` (or `superpowers:executing-plans`).
Plans are written in detail one step ahead: a plan is only detailed once
the interfaces it consumes exist in code.

| # | Plan | Repos touched | Status |
|---|---|---|---|
| 1 | `2026-09-02-phase1-green-board.md` | identity-access-service, loyalty-service, cash-management-service, provider-acquisition-service, repo-orchestration | detailed, ready |
| 2 | `2026-09-02-phase2-3-contracts-v2-identity.md` | laravel-api-contracts, identity-access-service, review-rating-service | detailed, ready |
| 3 | `phase4-service-clients-sdk.md` | new `laravel-service-clients` package, sofrito | outline below; detail after plan 2 lands |
| 4 | `phase5-notification-service-standard.md` | notification-service | outline below; detail after plan 2 lands |
| 5 | `phase6-tabibihub-wiring.md` | tabibihub | outline below; detail after plans 3 and 4 land |
| 6 | `phase7-muhamihub-wiring.md` | muhamiHub | outline below; detail after plans 3 and 4 land |
| 7 | `phase8-hygiene-and-checklist.md` | packages/laravel-roles, packages/cms-faq, repo-orchestration, every in-scope repo's CLAUDE.md | outline below |

Execution order is the table order. Plans 3 and 4 are independent of each
other and may run in parallel once plan 2 is merged.

## Deviations from the spec agreed at planning time

- **Path repositories stay during phases 1–7.** Services in this workspace
  consume `enadstack/laravel-api-contracts` through a Composer `path`
  repository today. Switching every service to GitHub VCS tags is a
  hygiene step in plan 7, not a prerequisite. Apps (Sofrito, TabibiHub,
  MuhamiHub) install the SDK via GitHub VCS from the start because that is
  how they already consume `blogify`.
- **Outbox table gains `next_attempt_at`.** The spec's column list omitted
  it; the backoff rule in spec §5 needs it.

## Outlines for later plans

### Plan 3 — `enadstack/laravel-service-clients` + Sofrito migration

1. Scaffold package at `/Users/enadabuzaid/Herd/packages/laravel-service-clients`
   (Testbench, `illuminate/* ^12|^13`), `ServiceClientsServiceProvider`,
   `config/service-clients.php` as in spec §4.2.
2. `ServiceClient` abstract + `ServiceException` (implements
   `Enadstack\ApiContracts\Contracts\HasErrorCode`) with tests for success,
   error envelope, connection failure, unconfigured.
3. `IdentityClient` = Sofrito's `app/Services/Identity/IdentityServiceClient.php`
   moved verbatim, plus `importUser()`. Tests ported from
   `sofrito/tests/Feature/Identity/IdentityServiceClientServiceKeyTest.php`.
4. `ReviewClient` = Sofrito's `app/Services/Reviews/ReviewServiceClient.php`
   moved, `X-Service-Token` header replaced by bearer key.
5. `NotificationClient::send(NotificationRequest): NotificationReceipt`.
6. `AnalyticsClient` from Sofrito's analytics calls.
7. Fakes: `IdentityClient::fake()` etc. (container swap + recorded calls +
   `assertSent`).
8. Push to GitHub `Enadabuzaid/laravel-service-clients`, tag `v1.0.0`.
9. Sofrito: require the SDK via VCS; bind
   `App\Services\Identity\IdentityServiceClient` → `IdentityClient` and
   `App\Services\Reviews\ReviewServiceClient` → `ReviewClient` through class
   aliases so callers are untouched; move config to
   `config/service-clients.php`; full suite green; delete the two local
   client classes.

### Plan 4 — notification-service on the standard

1. Require contracts v2; `bootstrap/app.php`: `ApiExceptionRenderer`,
   `service.auth` + `tenant.context` on the api group.
2. `POST /v1/notifications` → `service.scope:notifications:send`; persist
   `channel`, `application_reference`, `tenant_reference`; accept optional
   `category`, `priority`.
3. Delete `app/Http/Middleware/CheckApiScope.php` and the four
   `fix_*.php` scripts.
4. Templates: `POST /v1/templates`, `PUT /v1/templates/{code}` behind
   `service.scope:notifications:templates.manage`, scoped by caller
   application; creates `notification_templates` + `template_versions` +
   `template_channels`.
5. Dev SMTP provider config seeder from `MAIL_*` env.
6. Publish contracts outbox migration; emit `notification.sent` /
   `notification.failed`.
7. `tests/Feature/ServiceAuthTest.php` matrix; existing 12 tests green.

### Plan 5 — TabibiHub wiring

1. Require SDK; `config/service-clients.php`; `SERVICE_APPLICATION_CODE=tabibihub`.
2. Migration `users.identity_public_id` uuid nullable unique.
3. `FortifyServiceProvider::authenticateUsing` → `IdentityClient::login`;
   link by public id or email; hard cutover; error-code → message map.
4. `CreateNewUser` and `Admin/UserManagementController` create in identity
   first, abort on failure.
5. Logout revokes identity session best-effort.
6. `app:migrate-users-to-identity` (dry-run, resumable, idempotent) using
   `IdentityClient::importUser` with `tenant_reference = tabibihub:tenant:{ulid}`.
7. `notifications:setup` command registers the welcome template;
   `TenantWelcomeNotification` sends through `NotificationClient` when
   configured.
8. Tests on SDK fakes; 71-file suite green.

### Plan 6 — MuhamiHub wiring

Same eight steps as plan 5 with `muhamihub` codes, `tenant_reference =
muhamihub:tenant:{id}`, and the `SuperAdmin`/`TenantAdmin` invitation mails.

### Plan 7 — hygiene, checklist, smoke

1. `packages/laravel-roles`: `illuminate/support ^12.0|^13.0`; move the 56
   root markdown files to `docs/archive/`; tag.
2. `packages/cms-faq`: constraint bump; `git init` + push.
3. `repo-orchestration/ADOPTION-CHECKLIST.md` for the parked commerce
   services (install contracts, replace auth, replace tenancy vocabulary,
   delete `fix_*.php`, add `docs/SERVICE.md`, add `ServiceAuthTest`).
4. `repo-orchestration/scripts/smoke.sh` against Herd `.test` domains.
5. Every in-scope repo: `CLAUDE.md` gets a "Project standard" section
   linking `STANDARD.md` in this repo.
6. Convert in-workspace services from `path` to VCS for the contracts
   package.
