# Phase 1 — Green Board Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every in-scope test suite is green and every in-scope repo is under version control before any restructuring starts.

**Architecture:** Five independent, small fixes in five repos: wire identity's missing role routes and fix one test, delete identity's migration-rewrite scripts, align loyalty's two newest tables to the ULID keys the rest of the schema uses, isolate cash-management's trigger tests in savepoints, and put provider-acquisition-service under git. No behaviour change beyond what the existing tests already specify.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12 (identity), Pest 3 on PostgreSQL (loyalty, cash-management), `gh` CLI.

**Spec:** `docs/superpowers/specs/2026-09-02-platform-standard-design.md` (§2 non-goals exception, §4.3, §6, §7 step 1)

## Global Constraints

- Do not run `composer update` in any repo. `vendor/` is already installed.
- Work on the repo's current branch (`dev` for services). Commit per task with the trailer lines shown in each commit step.
- Never delete or weaken a test to make it pass. The only test edit allowed is the one in Task 1 Step 6 (a nonexistent assertion method).
- PostgreSQL suites (loyalty, cash-management) need the local databases `loyalty_service_testing` and `cash_management_service_testing`, which already exist.
- Test commands: identity uses `php artisan test --compact`; loyalty and cash-management use `vendor/bin/pest --compact`.
- macOS has no `timeout` binary. Rely on the tool's own timeout.

---

### Task 1: Restore identity's role routes and fix the impersonation test

**Files:**
- Modify: `/Users/enadabuzaid/Herd/identity-access-service/routes/api.php` (import block at top; the `Route::prefix('v1')->middleware('auth:api_session')` group that currently starts with the `api-keys` resource, before the `users/{user}/roles` line)
- Modify: `/Users/enadabuzaid/Herd/identity-access-service/tests/Feature/Impersonation/ImpersonationApiTest.php:175-178`
- Test: `/Users/enadabuzaid/Herd/identity-access-service/tests/Feature/Roles/RoleApiTest.php` (exists, currently 9 failures)

**Interfaces:**
- Consumes: `App\Http\Controllers\Api\V1\RoleController` methods `index`, `store`, `show`, `update`, `syncPermissions`, `archive` (all exist). `permission:` middleware alias (exists in `bootstrap/app.php`). FormRequests `CreateRoleRequest`, `UpdateRoleRequest`, `SyncRolePermissionsRequest`, `ArchiveRoleRequest` gate writes with `roles.manage` via the `RequiresPermission` trait, which throws `PermissionDeniedException` (403).
- Produces: HTTP routes `GET /api/v1/roles`, `GET /api/v1/roles/{role}`, `POST /api/v1/roles`, `PATCH /api/v1/roles/{role}`, `POST /api/v1/roles/{role}/permissions`, `POST /api/v1/roles/{role}/archive`. Response keys `roles` (index) and `role` (show/store/update).

- [ ] **Step 1: Run the role tests to confirm the current failures**

Run: `cd /Users/enadabuzaid/Herd/identity-access-service && php artisan test --compact tests/Feature/Roles/RoleApiTest.php`
Expected: 9 failures, all of the form `Expected response status code [...] but received 404` (plus one `Failed asserting that 1 is identical to 2` and one `201` instead of `422`, both consequences of the archive/sync routes returning 404).

- [ ] **Step 2: Add the controller import**

In `routes/api.php`, the import block at the top lists controllers alphabetically. Add, in alphabetical position (after `PermissionController`, before `ServiceAccountController` or wherever `R` sorts):

```php
use App\Http\Controllers\Api\V1\RoleController;
```

- [ ] **Step 3: Add the role routes**

Inside the group that begins `Route::prefix('v1')->middleware('auth:api_session')->group(function () {` (the one containing `Route::apiResource('api-keys', ...)`), insert this block immediately **before** the comment line `// Phase 11: User Role Assignments`:

```php
    // Phase 10: Roles — reads gated by route middleware (no request body),
    // writes gated inside their FormRequests via RequiresPermission('roles.manage').
    Route::middleware('permission:roles.view')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('roles/{role}', [RoleController::class, 'show']);
    });
    Route::post('roles', [RoleController::class, 'store']);
    Route::patch('roles/{role}', [RoleController::class, 'update']);
    Route::post('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);
    Route::post('roles/{role}/archive', [RoleController::class, 'archive']);
```

- [ ] **Step 4: Run the role tests**

Run: `php artisan test --compact tests/Feature/Roles/RoleApiTest.php`
Expected: 9 passed. If `test_a_non_admin_cannot_create_a_role` returns 401 instead of 403, the non-admin user has no tenant context; that is not expected because the request reaches `CreateRoleRequest::authorize()` which throws the 403. Report the actual status rather than editing the test.

- [ ] **Step 5: Run the impersonation test to confirm the error**

Run: `php artisan test --compact tests/Feature/Impersonation/ImpersonationApiTest.php --filter=test_impersonation_session_continues_to_work_even_if_target_user_is_globally_suspended`
Expected: 1 error, `Method Illuminate\Http\JsonResponse::assertNotNull does not exist.`

- [ ] **Step 6: Replace the nonexistent assertion**

In `tests/Feature/Impersonation/ImpersonationApiTest.php`, replace the final chained call of that test (currently lines 175–178):

```php
        $this->withToken($impersonationToken)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.public_id', $target->public_id)
            ->assertNotNull('impersonation');
```

with:

```php
        $response = $this->withToken($impersonationToken)->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('user.public_id', $target->public_id);
        $this->assertNotNull($response->json('impersonation'), 'The /me payload must still flag the session as an impersonation.');
```

(`AuthController::me` returns an `impersonation` object when the session has an `impersonation_session_id`, see `app/Http/Controllers/Api/V1/Auth/AuthController.php:245`.)

- [ ] **Step 7: Run the whole identity suite**

Run: `php artisan test --compact`
Expected: 187 tests, 0 failures, 0 errors.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add routes/api.php tests/Feature/Impersonation/ImpersonationApiTest.php
git commit -m "fix(identity): wire the role management routes and repair the impersonation assertion

RoleController and its FormRequests existed but routes/api.php never
registered them, so the nine RoleApiTest cases 404'd. The impersonation
test called a TestResponse method that does not exist.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 2: Delete identity's migration-rewrite scripts

**Files:**
- Delete: `/Users/enadabuzaid/Herd/identity-access-service/fix_users_migration.php`
- Delete: `/Users/enadabuzaid/Herd/identity-access-service/fix_session_migrations.php`
- Delete: `/Users/enadabuzaid/Herd/identity-access-service/fix_memberships_roles_migrations.php`
- Delete: `/Users/enadabuzaid/Herd/identity-access-service/fix_phase13_16_migrations.php`
- Delete: `/Users/enadabuzaid/Herd/identity-access-service/fix_remaining_migrations.php`

**Interfaces:**
- Consumes: nothing. These scripts `glob()` a migration file and overwrite it with a heredoc; they were one-shot generator hacks and nothing references them.
- Produces: a clean repo root.

- [ ] **Step 1: Prove nothing references the scripts**

Run: `cd /Users/enadabuzaid/Herd/identity-access-service && grep -rn "fix_users_migration\|fix_session_migrations\|fix_memberships_roles_migrations\|fix_phase13_16_migrations\|fix_remaining_migrations" --include=*.php --include=*.json --include=*.md --include=*.yml --include=*.yaml --include=*.sh . --exclude-dir=vendor --exclude-dir=node_modules`
Expected: no output.

- [ ] **Step 2: Delete them**

```bash
git rm fix_users_migration.php fix_session_migrations.php fix_memberships_roles_migrations.php fix_phase13_16_migrations.php fix_remaining_migrations.php
```

- [ ] **Step 3: Confirm migrations still run from scratch**

Run: `php artisan migrate:fresh --env=testing --force 2>&1 | tail -3 && php artisan test --compact`
Expected: migrations complete; 187 tests pass.

- [ ] **Step 4: Commit**

```bash
git commit -m "chore(identity): remove one-shot migration rewrite scripts

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 3: Loyalty tiers use ULID keys like the rest of the schema

**Files:**
- Modify: `/Users/enadabuzaid/Herd/loyalty-service/database/migrations/2026_09_01_142252_create_loyalty_tiers_table.php`
- Modify: `/Users/enadabuzaid/Herd/loyalty-service/database/migrations/2026_09_01_142253_create_loyalty_tier_history_table.php`
- Modify: `/Users/enadabuzaid/Herd/loyalty-service/app/Models/LoyaltyTier.php`
- Modify: `/Users/enadabuzaid/Herd/loyalty-service/app/Models/LoyaltyTierHistory.php`
- Test: existing suite (63 tests, currently 62 errors)

**Interfaces:**
- Consumes: `loyalty_programs.id` is `ulid` (`LoyaltyProgram` uses `HasUlids`); `loyalty_accounts.id` is `ulid`; `loyalty_accounts.current_tier_id` is already declared `ulid` nullable in `2026_08_23_000011_create_loyalty_accounts_table.php`.
- Produces: `loyalty_tiers.id` ulid, `loyalty_tiers.program_id` ulid FK; `loyalty_tier_history.id` ulid, `account_id`/`from_tier_id`/`to_tier_id` ulid FKs. `LoyaltyTier` and `LoyaltyTierHistory` models generate ULIDs.

- [ ] **Step 1: Reproduce**

Run: `cd /Users/enadabuzaid/Herd/loyalty-service && vendor/bin/pest --compact 2>&1 | tail -5`
Expected: errors mentioning `SQLSTATE[42804]: Datatype mismatch ... foreign key constraint "loyalty_tiers_program_id_foreign"`.

- [ ] **Step 2: Fix the tiers migration**

In `2026_09_01_142252_create_loyalty_tiers_table.php`, replace:

```php
            $table->id();

            $table->foreignId('program_id')->constrained('loyalty_programs')->cascadeOnDelete();
```

with:

```php
            $table->ulid('id')->primary();

            $table->foreignUlid('program_id')->constrained('loyalty_programs')->cascadeOnDelete();
```

- [ ] **Step 3: Fix the tier-history migration**

In `2026_09_01_142253_create_loyalty_tier_history_table.php`, replace:

```php
            $table->id();

            $table->foreignId('account_id')->constrained('loyalty_accounts')->cascadeOnDelete();

            $table->foreignId('from_tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $table->foreignId('to_tier_id')->constrained('loyalty_tiers')->cascadeOnDelete();
```

with:

```php
            $table->ulid('id')->primary();

            $table->foreignUlid('account_id')->constrained('loyalty_accounts')->cascadeOnDelete();

            $table->foreignUlid('from_tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();
            $table->foreignUlid('to_tier_id')->constrained('loyalty_tiers')->cascadeOnDelete();
```

- [ ] **Step 4: Make the two models generate ULIDs**

`app/Models/LoyaltyTier.php` — add the import and trait:

```php
use Illuminate\Database\Eloquent\Concerns\HasUlids;
```

```php
class LoyaltyTier extends Model
{
    use HasFactory, HasUlids;
```

`app/Models/LoyaltyTierHistory.php` — same two edits:

```php
use Illuminate\Database\Eloquent\Concerns\HasUlids;
```

```php
class LoyaltyTierHistory extends Model
{
    use HasFactory, HasUlids;
```

- [ ] **Step 5: Run the suite**

Run: `vendor/bin/pest --compact 2>&1 | tail -15`
Expected: the 62 migration errors are gone. If `LoyaltyLedgerTest` (negative-balance exception) still fails, read its failure message and the action under test before changing anything; it is likely a second, unrelated defect. Fix only if the cause is a one-line assertion/exception-type mismatch; otherwise record the exact message in the task report and leave it for the reviewer.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_09_01_142252_create_loyalty_tiers_table.php database/migrations/2026_09_01_142253_create_loyalty_tier_history_table.php app/Models/LoyaltyTier.php app/Models/LoyaltyTierHistory.php
git commit -m "fix(loyalty): use ULID keys for tiers and tier history

loyalty_programs and loyalty_accounts use ULID primary keys; the two
tables added on 2026-09-01 used bigint ids and foreignId() columns, so
the foreign keys could not be created and every test errored at
migration.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 4: Cash-management trigger tests run inside savepoints

**Files:**
- Modify: `/Users/enadabuzaid/Herd/cash-management-service/tests/Unit/Models/CashLedgerEntryTest.php` (the four tests whose names contain "fails at the database level")

**Interfaces:**
- Consumes: the `cash_ledger_entries` append-only trigger from `database/migrations/2026_08_23_000008_add_cash_ledger_entries_append_only_trigger.php`, which raises `cash_ledger_entries is append-only`. Tests run under `LazilyRefreshDatabase`, i.e. inside one wrapping transaction.
- Produces: nothing new; the four tests pass on PostgreSQL.

Why: PostgreSQL aborts the whole transaction when the trigger raises. The assertion that follows (`fresh()`, `exists()`) then runs inside the aborted transaction and fails with `SQLSTATE[25P02] current transaction is aborted`. Wrapping the mutation in `DB::transaction()` creates a savepoint, Laravel rolls back to it on exception, and the outer transaction stays usable.

- [ ] **Step 1: Reproduce**

Run: `cd /Users/enadabuzaid/Herd/cash-management-service && vendor/bin/pest --compact tests/Unit/Models/CashLedgerEntryTest.php 2>&1 | tail -12`
Expected: 4 errors containing `SQLSTATE[25P02]`.

- [ ] **Step 2: Wrap each of the four database-level mutations in a savepoint**

Replace the four `expect(fn () => ...)` closures with these exact versions.

Mass-Builder update:

```php
    expect(fn () => DB::transaction(fn () => CashLedgerEntry::query()->whereKey($entry->id)->update(['description' => 'edited'])))
        ->toThrow(QueryException::class, 'cash_ledger_entries is append-only');
```

Mass-Builder delete:

```php
    expect(fn () => DB::transaction(fn () => CashLedgerEntry::query()->whereKey($entry->id)->delete()))
        ->toThrow(QueryException::class, 'cash_ledger_entries is append-only');
```

Raw update:

```php
    expect(fn () => DB::transaction(fn () => DB::table('cash_ledger_entries')->where('id', $entry->id)->update(['description' => 'tampered'])))
        ->toThrow(QueryException::class, 'cash_ledger_entries is append-only');
```

Raw delete:

```php
    expect(fn () => DB::transaction(fn () => DB::table('cash_ledger_entries')->where('id', $entry->id)->delete()))
        ->toThrow(QueryException::class, 'cash_ledger_entries is append-only');
```

Leave every `expect(...)->toBe('original')` / `->toBeTrue()` line after them unchanged. Add a comment above the first of the four tests:

```php
// The trigger raises inside PostgreSQL, which aborts the current transaction.
// Wrapping the mutation in DB::transaction() gives it a savepoint to roll
// back to, so the outer test transaction stays usable for the assertions.
```

- [ ] **Step 3: Run the file**

Run: `vendor/bin/pest --compact tests/Unit/Models/CashLedgerEntryTest.php`
Expected: 9 passed.

- [ ] **Step 4: Run the whole suite**

Run: `vendor/bin/pest --compact 2>&1 | tail -5`
Expected: 99 passed, 0 errors.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Unit/Models/CashLedgerEntryTest.php
git commit -m "test(cash): isolate append-only trigger assertions in savepoints

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 5: Put provider-acquisition-service under git and in the inventory

**Files:**
- Create: `/Users/enadabuzaid/Herd/provider-acquisition-service/.git` (via `git init`)
- Modify: `/Users/enadabuzaid/Herd/repo-orchestration/repos.yaml` (append one entry)
- Modify: `/Users/enadabuzaid/Herd/repo-orchestration/repos.txt` (append one line)

**Interfaces:**
- Consumes: `gh` CLI authenticated as `Enadabuzaid` (verified 2026-09-02). Existing `.gitignore`, `.gitattributes`, `.github/` in the repo. Branch convention from `repo-orchestration/scripts/bootstrap-github-repos.sh`: `main`, `staging`, `testing`, `dev`; work happens on `dev`.
- Produces: remote `git@github.com:Enadabuzaid/provider-acquisition-service.git` with the four branches; inventory entry.

- [ ] **Step 1: Confirm the repo is not versioned and tests pass**

Run: `cd /Users/enadabuzaid/Herd/provider-acquisition-service && ls -d .git 2>&1; php artisan test --compact 2>&1 | tail -3`
Expected: `ls: .git: No such file or directory`; 13 tests pass.

- [ ] **Step 2: Check the ignore file covers secrets and vendor**

Run: `grep -n "^/vendor\|^\.env\|^/node_modules\|^/storage" .gitignore`
Expected: `/vendor`, `.env`, `/storage/*.key` (or equivalent) present. If `.env` is not ignored, add a line `.env` and `.env.*` before continuing.

- [ ] **Step 3: Initialise and make the first commit on main**

```bash
git init
git checkout -b main
git add .
git status --short | grep -c "" 
git commit -m "chore: initial import of provider-acquisition-service

Cross-vertical provider discovery, lead scoring and claim workflow
service (Sofrito first, Souqna/TabibiHub/MuhamiHub adapters planned).

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

Before committing, run `git status --short | grep "\.env$"` and confirm it prints nothing.

- [ ] **Step 4: Create the private remote and push all four branches**

```bash
gh repo create Enadabuzaid/provider-acquisition-service --private --description "Reusable provider acquisition service for provider discovery, deduplication, lead scoring, sales pipeline, and provider claim/verification workflows."
git remote add origin git@github.com:Enadabuzaid/provider-acquisition-service.git
git push -u origin main
for b in staging testing dev; do git checkout -b $b && git push -u origin $b; done
git checkout dev
```

Expected: four pushes succeed; `git branch --show-current` prints `dev`.

- [ ] **Step 5: Add the repo to the orchestration inventory**

Append to `/Users/enadabuzaid/Herd/repo-orchestration/repos.yaml` (inside the `repositories:` list, after the `report-service` entry):

```yaml
  - name: provider-acquisition-service
    description: Reusable provider acquisition service for provider discovery, deduplication, lead scoring, sales pipeline, and provider claim/verification workflows.
```

Append to `/Users/enadabuzaid/Herd/repo-orchestration/repos.txt` (after the `report-service|...` line):

```
provider-acquisition-service|Reusable provider acquisition service for provider discovery, deduplication, lead scoring, sales pipeline, and provider claim/verification workflows.
```

`repo-orchestration` is not a git repo; there is nothing to commit there.

- [ ] **Step 6: Verify**

Run: `gh repo view Enadabuzaid/provider-acquisition-service --json name,visibility,defaultBranchRef -q '.name+" "+.visibility+" "+.defaultBranchRef.name'`
Expected: `provider-acquisition-service PRIVATE main`.

---

## Self-review against the spec

- §4.3 "Restore the roles routes", "impersonation/gate tests green", "Delete the five fix_*.php scripts" → Tasks 1–2.
- §2 exception "loyalty's ULID/bigint foreign key, cash-management's aborted-transaction tests" → Tasks 3–4.
- §4.6 "provider-acquisition-service: git init, initial commit, push, add to repos.yaml" → Task 5.
- §8 "No fix_*.php scripts remain in in-scope services": identity done here; notification-service and review-rating scripts are removed in their own plans.
