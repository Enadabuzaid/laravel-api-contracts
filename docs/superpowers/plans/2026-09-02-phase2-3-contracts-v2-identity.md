# Phases 2–3 — Contracts v2 and Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `enadstack/laravel-api-contracts` into the enforceable platform standard (references, service auth via identity introspection, tenant context, idempotency, outbox, test fakes), make identity able to issue application-aware service credentials from one command, and prove the standard on review-rating-service.

**Architecture:** The package ships middleware and helpers a service installs; identity remains the only credential authority and gains an `application` on service accounts; review-rating swaps its shared secret for the package middleware and becomes the reference consumer. Every unit is small, has one job, and is tested in isolation with Orchestra Testbench.

**Tech Stack:** PHP 8.3+, Laravel 12/13 (`illuminate/*`), Orchestra Testbench 10/11, PHPUnit 11/12, Laravel HTTP client, `Illuminate\Support\Facades\Context`.

**Spec:** `docs/superpowers/specs/2026-09-02-platform-standard-design.md` (§3, §4.1, §4.3, §4.5, §5, §6, §7 steps 2–3)

## Global Constraints

- Package namespace is `Enadstack\ApiContracts`; PSR-4 root `src/`; tests under `tests/` in namespace `Enadstack\ApiContracts\Tests` extending `Enadstack\ApiContracts\Tests\TestCase` (Testbench).
- Package `composer.json` must end up with `"illuminate/*": "^12.0|^13.0"`, `"orchestra/testbench": "^10.0|^11.0"`, `"phpunit/phpunit": "^11.0|^12.0"`. Do not narrow these.
- "Contracts v2" in the spec is released as package tag **`v1.1.0`**: every change is additive, so existing `^1.0` constraints keep resolving.
- Error envelope stays `{"error":{"code","message","details"}}` with `details` always a JSON object. Success bodies stay flat; only pagination uses `data`.
- Error codes introduced here, exact strings: `UNAUTHORIZED` (401), `FORBIDDEN` (403), `IDENTITY_UNAVAILABLE` (503), `INVALID_REFERENCE` (422), `INVALID_TENANT_REFERENCE` (422), `MISSING_SCOPE` is **not** a code; missing scope uses `FORBIDDEN` with `details.required_scope`.
- Middleware aliases, exact strings: `service.auth`, `service.scope`, `tenant.context`, `idempotency`.
- Reference format: `{application}:{type}:{id}` matching `^[a-z][a-z0-9_-]*:[a-z][a-z0-9_-]*:[A-Za-z0-9_.-]+$`.
- Service tokens start with `ia_live_`; identity introspect endpoint is `GET /api/v1/api-key/introspect` with the same bearer token.
- Package tests run with `cd /Users/enadabuzaid/Herd/laravel-api-contracts && vendor/bin/phpunit`. Identity and review-rating tests run with `php artisan test --compact`.
- Do not run `composer update` in identity or review-rating; they consume the package through a `path` repository (symlink), so package changes are live immediately. Running `composer update` in the package itself is allowed and required in Task 7.
- Commit per task with the trailer lines shown in each commit step.

---

### Task 1: `Reference` value object and validation rule

**Files:**
- Create: `src/Support/Reference.php`
- Create: `src/Exceptions/InvalidReferenceException.php`
- Create: `src/Rules/ReferenceRule.php`
- Test: `tests/Unit/ReferenceTest.php`
- Test: `tests/Unit/ReferenceRuleTest.php`

**Interfaces:**
- Consumes: `Enadstack\ApiContracts\Contracts\HasErrorCode` (exists; methods `errorCode(): string`, `errorDetails(): array`, `errorStatus(): int`).
- Produces:
  - `Reference::make(string $application, string $type, string|int $id): Reference`
  - `Reference::parse(string $value): Reference` (throws `InvalidReferenceException`)
  - `Reference::tryParse(?string $value): ?Reference`
  - `Reference::isValid(?string $value): bool`
  - `$ref->application`, `$ref->type`, `$ref->id` (public readonly strings)
  - `$ref->is(string $type): bool`, `$ref->belongsTo(string $application): bool`, `(string) $ref`, `json_encode($ref)` → string
  - `new ReferenceRule(?string $type = null, ?string $application = null)` — Laravel `ValidationRule`
  - `InvalidReferenceException` — `HasErrorCode`, code `INVALID_REFERENCE`, status 422, details `{"value": "<input>"}`

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ReferenceTest.php`:

```php
<?php

namespace Enadstack\ApiContracts\Tests\Unit;

use Enadstack\ApiContracts\Exceptions\InvalidReferenceException;
use Enadstack\ApiContracts\Support\Reference;
use Enadstack\ApiContracts\Tests\TestCase;

class ReferenceTest extends TestCase
{
    public function test_it_formats_application_type_and_id(): void
    {
        $ref = Reference::make('sofrito', 'tenant', 42);

        $this->assertSame('sofrito:tenant:42', (string) $ref);
        $this->assertSame('sofrito', $ref->application);
        $this->assertSame('tenant', $ref->type);
        $this->assertSame('42', $ref->id);
    }

    public function test_it_parses_a_valid_string(): void
    {
        $ref = Reference::parse('tabibihub:tenant:01J8ZQ2K3M4N5P6Q7R8S9T0V1W');

        $this->assertTrue($ref->is('tenant'));
        $this->assertTrue($ref->belongsTo('tabibihub'));
        $this->assertFalse($ref->belongsTo('sofrito'));
        $this->assertSame('01J8ZQ2K3M4N5P6Q7R8S9T0V1W', $ref->id);
    }

    public function test_it_rejects_malformed_strings(): void
    {
        foreach (['', 'sofrito', 'sofrito:tenant', 'Sofrito:tenant:1', 'sofrito:tenant:', 'sofrito:tenant:a b', 'sofrito:tenant:1:2'] as $bad) {
            $this->assertFalse(Reference::isValid($bad), "Expected [{$bad}] to be invalid");
            $this->assertNull(Reference::tryParse($bad));
        }

        $this->expectException(InvalidReferenceException::class);
        Reference::parse('nope');
    }

    public function test_the_exception_carries_the_error_contract(): void
    {
        try {
            Reference::parse('bad value');
            $this->fail('Expected exception');
        } catch (InvalidReferenceException $e) {
            $this->assertSame('INVALID_REFERENCE', $e->errorCode());
            $this->assertSame(422, $e->errorStatus());
            $this->assertSame(['value' => 'bad value'], $e->errorDetails());
        }
    }

    public function test_it_json_serializes_to_its_string_form(): void
    {
        $this->assertSame('"sofrito:user:7"', json_encode(Reference::make('sofrito', 'user', 7)));
    }

    public function test_make_rejects_invalid_parts(): void
    {
        $this->expectException(InvalidReferenceException::class);
        Reference::make('Sofrito', 'tenant', 1);
    }
}
```

`tests/Unit/ReferenceRuleTest.php`:

```php
<?php

namespace Enadstack\ApiContracts\Tests\Unit;

use Enadstack\ApiContracts\Rules\ReferenceRule;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Support\Facades\Validator;

class ReferenceRuleTest extends TestCase
{
    public function test_it_accepts_a_valid_reference(): void
    {
        $v = Validator::make(['ref' => 'sofrito:tenant:1'], ['ref' => [new ReferenceRule]]);

        $this->assertTrue($v->passes());
    }

    public function test_it_rejects_an_invalid_reference(): void
    {
        $v = Validator::make(['ref' => 'nope'], ['ref' => [new ReferenceRule]]);

        $this->assertFalse($v->passes());
        $this->assertSame('The ref must be a reference in the form application:type:id.', $v->errors()->first('ref'));
    }

    public function test_it_can_require_a_type(): void
    {
        $v = Validator::make(['ref' => 'sofrito:user:1'], ['ref' => [new ReferenceRule(type: 'tenant')]]);

        $this->assertFalse($v->passes());
        $this->assertSame('The ref must be a tenant reference.', $v->errors()->first('ref'));
    }

    public function test_it_can_require_an_application(): void
    {
        $v = Validator::make(['ref' => 'sofrito:tenant:1'], ['ref' => [new ReferenceRule(application: 'tabibihub')]]);

        $this->assertFalse($v->passes());
        $this->assertSame('The ref must belong to the tabibihub application.', $v->errors()->first('ref'));
    }
}
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `cd /Users/enadabuzaid/Herd/laravel-api-contracts && vendor/bin/phpunit tests/Unit/ReferenceTest.php tests/Unit/ReferenceRuleTest.php`
Expected: errors `Class "Enadstack\ApiContracts\Support\Reference" not found`.

- [ ] **Step 3: Implement the exception**

`src/Exceptions/InvalidReferenceException.php`:

```php
<?php

namespace Enadstack\ApiContracts\Exceptions;

use Enadstack\ApiContracts\Contracts\HasErrorCode;
use InvalidArgumentException;

class InvalidReferenceException extends InvalidArgumentException implements HasErrorCode
{
    public function __construct(private readonly string $value)
    {
        parent::__construct("[{$value}] is not a valid reference (expected application:type:id).");
    }

    public function errorCode(): string
    {
        return 'INVALID_REFERENCE';
    }

    /** @return array<string, mixed> */
    public function errorDetails(): array
    {
        return ['value' => $this->value];
    }

    public function errorStatus(): int
    {
        return 422;
    }
}
```

- [ ] **Step 4: Implement the value object**

`src/Support/Reference.php`:

```php
<?php

namespace Enadstack\ApiContracts\Support;

use Enadstack\ApiContracts\Exceptions\InvalidReferenceException;
use JsonSerializable;
use Stringable;

/**
 * An opaque cross-service identifier in the form {application}:{type}:{id},
 * e.g. "sofrito:tenant:42". Services store these as strings and never own
 * the referenced table.
 */
final class Reference implements JsonSerializable, Stringable
{
    public const PATTERN = '/^([a-z][a-z0-9_-]*):([a-z][a-z0-9_-]*):([A-Za-z0-9_.-]+)$/';

    private function __construct(
        public readonly string $application,
        public readonly string $type,
        public readonly string $id,
    ) {}

    public static function make(string $application, string $type, string|int $id): self
    {
        return self::parse("{$application}:{$type}:{$id}");
    }

    public static function parse(string $value): self
    {
        if (! preg_match(self::PATTERN, $value, $m)) {
            throw new InvalidReferenceException($value);
        }

        return new self($m[1], $m[2], $m[3]);
    }

    public static function tryParse(?string $value): ?self
    {
        if ($value === null || ! self::isValid($value)) {
            return null;
        }

        return self::parse($value);
    }

    public static function isValid(?string $value): bool
    {
        return $value !== null && preg_match(self::PATTERN, $value) === 1;
    }

    public function is(string $type): bool
    {
        return $this->type === $type;
    }

    public function belongsTo(string $application): bool
    {
        return $this->application === $application;
    }

    public function toString(): string
    {
        return "{$this->application}:{$this->type}:{$this->id}";
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
```

- [ ] **Step 5: Implement the validation rule**

`src/Rules/ReferenceRule.php`:

```php
<?php

namespace Enadstack\ApiContracts\Rules;

use Closure;
use Enadstack\ApiContracts\Support\Reference;
use Illuminate\Contracts\Validation\ValidationRule;

class ReferenceRule implements ValidationRule
{
    public function __construct(
        private readonly ?string $type = null,
        private readonly ?string $application = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ref = is_string($value) ? Reference::tryParse($value) : null;

        if ($ref === null) {
            $fail("The {$attribute} must be a reference in the form application:type:id.");

            return;
        }

        if ($this->type !== null && ! $ref->is($this->type)) {
            $fail("The {$attribute} must be a {$this->type} reference.");

            return;
        }

        if ($this->application !== null && ! $ref->belongsTo($this->application)) {
            $fail("The {$attribute} must belong to the {$this->application} application.");
        }
    }
}
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/ReferenceTest.php tests/Unit/ReferenceRuleTest.php`
Expected: 10 tests pass.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add src/Support/Reference.php src/Exceptions/InvalidReferenceException.php src/Rules/ReferenceRule.php tests/Unit/ReferenceTest.php tests/Unit/ReferenceRuleTest.php
git commit -m "feat: Reference value object and validation rule

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 2: `ServiceCaller`, `Introspector`, `HttpIntrospector`, and the test fake

**Files:**
- Create: `src/Auth/ServiceCaller.php`
- Create: `src/Auth/Introspector.php`
- Create: `src/Auth/HttpIntrospector.php`
- Create: `src/Auth/FakeIntrospector.php`
- Create: `src/Testing/ServiceAuth.php`
- Create: `src/Exceptions/IdentityUnavailableException.php`
- Modify: `config/api-contracts.php` (add `service_auth` block)
- Modify: `src/ApiContractsServiceProvider.php` (bind `Introspector`)
- Test: `tests/Unit/ServiceCallerTest.php`
- Test: `tests/Unit/HttpIntrospectorTest.php`

**Interfaces:**
- Consumes: `Illuminate\Support\Facades\Http`, `Illuminate\Contracts\Cache\Repository`, `Illuminate\Http\Client\ConnectionException`, identity introspect payload `{active, api_key_id, scopes, expires_at, owner: {type: USER|SERVICE_ACCOUNT, public_id?|service_code?}, application?, tenant_reference?}` (`application` and `tenant_reference` are added to identity in Task 8; treat both as optional).
- Produces:
  - `ServiceCaller` readonly: `serviceCode`, `application`, `scopes`, `tenantReference`, `ownerType`; `hasScope(string): bool` (exact, `prefix:*`, or `*`); `fromIntrospection(array): ServiceCaller`; `toArray(): array`; `fromArray(array): ServiceCaller`; `fromRequest(Request): ?ServiceCaller` (reads request attribute `service_caller`)
  - `Introspector::introspect(string $token): ?ServiceCaller` — `null` means inactive/invalid; throws `IdentityUnavailableException`
  - `HttpIntrospector` with positive cache `svc-auth:{sha256}` for `service_auth.cache_ttl` seconds and negative cache for `service_auth.negative_ttl`
  - `ServiceAuth::fake(string $serviceCode = 'test-service', ?string $application = 'test-app', array $scopes = ['*'], ?string $tenantReference = null): FakeIntrospector`; `ServiceAuth::TEST_TOKEN` (= `'ia_live_'.str_repeat('t', 40)`)
  - `FakeIntrospector::rejecting(string ...$tokens): static`, `FakeIntrospector::unavailable(): static`
  - `IdentityUnavailableException` — `HasErrorCode`, code `IDENTITY_UNAVAILABLE`, 503

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ServiceCallerTest.php`:

```php
<?php

namespace Enadstack\ApiContracts\Tests\Unit;

use Enadstack\ApiContracts\Auth\ServiceCaller;
use Enadstack\ApiContracts\Tests\TestCase;

class ServiceCallerTest extends TestCase
{
    public function test_it_matches_exact_wildcard_and_global_scopes(): void
    {
        $caller = new ServiceCaller('sofrito-app', 'sofrito', ['notifications:send', 'reviews:*'], null);

        $this->assertTrue($caller->hasScope('notifications:send'));
        $this->assertTrue($caller->hasScope('reviews:moderate'));
        $this->assertFalse($caller->hasScope('notifications:templates.manage'));

        $this->assertTrue((new ServiceCaller('root', null, ['*'], null))->hasScope('anything:at-all'));
    }

    public function test_it_is_built_from_a_service_account_introspection_payload(): void
    {
        $caller = ServiceCaller::fromIntrospection([
            'active' => true,
            'scopes' => ['reviews:read'],
            'owner' => ['type' => 'SERVICE_ACCOUNT', 'id' => 3, 'service_code' => 'sofrito-app'],
            'application' => 'sofrito',
            'tenant_reference' => 'sofrito:tenant:9',
        ]);

        $this->assertSame('sofrito-app', $caller->serviceCode);
        $this->assertSame('sofrito', $caller->application);
        $this->assertSame('sofrito:tenant:9', $caller->tenantReference);
        $this->assertSame('SERVICE_ACCOUNT', $caller->ownerType);
    }

    public function test_a_user_owned_key_is_named_by_public_id(): void
    {
        $caller = ServiceCaller::fromIntrospection([
            'active' => true,
            'scopes' => [],
            'owner' => ['type' => 'USER', 'public_id' => 'abc-123', 'email' => 'a@b.c'],
        ]);

        $this->assertSame('user:abc-123', $caller->serviceCode);
        $this->assertNull($caller->application);
        $this->assertSame('USER', $caller->ownerType);
    }

    public function test_it_round_trips_through_arrays_for_caching(): void
    {
        $caller = new ServiceCaller('x', 'app', ['a:b'], 'app:tenant:1', 'SERVICE_ACCOUNT');

        $this->assertEquals($caller, ServiceCaller::fromArray($caller->toArray()));
    }
}
```

`tests/Unit/HttpIntrospectorTest.php`:

```php
<?php

namespace Enadstack\ApiContracts\Tests\Unit;

use Enadstack\ApiContracts\Auth\HttpIntrospector;
use Enadstack\ApiContracts\Auth\Introspector;
use Enadstack\ApiContracts\Exceptions\IdentityUnavailableException;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class HttpIntrospectorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('api-contracts.service_auth.identity_url', 'http://identity.test');
        $app['config']->set('api-contracts.service_auth.cache_ttl', 300);
        $app['config']->set('api-contracts.service_auth.negative_ttl', 60);
        $app['config']->set('cache.default', 'array');
    }

    private function activePayload(): array
    {
        return [
            'active' => true,
            'scopes' => ['reviews:read'],
            'owner' => ['type' => 'SERVICE_ACCOUNT', 'id' => 1, 'service_code' => 'sofrito-app'],
            'application' => 'sofrito',
            'tenant_reference' => null,
        ];
    }

    public function test_the_container_resolves_the_http_introspector(): void
    {
        $this->assertInstanceOf(HttpIntrospector::class, $this->app->make(Introspector::class));
    }

    public function test_it_calls_identity_with_the_bearer_token_and_caches_the_caller(): void
    {
        Http::fake(['identity.test/api/v1/api-key/introspect' => Http::response($this->activePayload())]);
        $token = 'ia_live_'.str_repeat('a', 40);

        $introspector = $this->app->make(Introspector::class);
        $first = $introspector->introspect($token);
        $second = $introspector->introspect($token);

        $this->assertSame('sofrito-app', $first->serviceCode);
        $this->assertEquals($first, $second);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.$token));
        $this->assertTrue(Cache::has('svc-auth:'.hash('sha256', $token)));
    }

    public function test_a_401_from_identity_is_a_null_caller_and_is_negatively_cached(): void
    {
        Http::fake(['identity.test/*' => Http::response(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'x', 'details' => (object) []]], 401)]);
        $token = 'ia_live_'.str_repeat('b', 40);

        $introspector = $this->app->make(Introspector::class);

        $this->assertNull($introspector->introspect($token));
        $this->assertNull($introspector->introspect($token));
        Http::assertSentCount(1);
    }

    public function test_a_connection_failure_throws_identity_unavailable(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        $this->expectException(IdentityUnavailableException::class);
        $this->app->make(Introspector::class)->introspect('ia_live_'.str_repeat('c', 40));
    }

    public function test_a_5xx_from_identity_throws_identity_unavailable(): void
    {
        Http::fake(['identity.test/*' => Http::response('', 502)]);

        $this->expectException(IdentityUnavailableException::class);
        $this->app->make(Introspector::class)->introspect('ia_live_'.str_repeat('d', 40));
    }

    public function test_a_cached_caller_survives_identity_going_down(): void
    {
        $token = 'ia_live_'.str_repeat('e', 40);
        Http::fake(['identity.test/*' => Http::response($this->activePayload())]);
        $this->app->make(Introspector::class)->introspect($token);

        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('down'));

        $this->assertSame('sofrito-app', $this->app->make(Introspector::class)->introspect($token)->serviceCode);
    }
}
```

- [ ] **Step 2: Run them to confirm they fail**

Run: `vendor/bin/phpunit tests/Unit/ServiceCallerTest.php tests/Unit/HttpIntrospectorTest.php`
Expected: class-not-found errors.

- [ ] **Step 3: Implement the exception**

`src/Exceptions/IdentityUnavailableException.php`:

```php
<?php

namespace Enadstack\ApiContracts\Exceptions;

use Enadstack\ApiContracts\Contracts\HasErrorCode;
use RuntimeException;
use Throwable;

class IdentityUnavailableException extends RuntimeException implements HasErrorCode
{
    public function __construct(string $message = 'The identity service could not be reached.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return 'IDENTITY_UNAVAILABLE';
    }

    /** @return array<string, mixed> */
    public function errorDetails(): array
    {
        return [];
    }

    public function errorStatus(): int
    {
        return 503;
    }
}
```

- [ ] **Step 4: Implement `ServiceCaller` and the `Introspector` contract**

`src/Auth/ServiceCaller.php`:

```php
<?php

namespace Enadstack\ApiContracts\Auth;

use Illuminate\Http\Request;

/**
 * The authenticated calling system for the current request, as resolved from
 * an identity-issued ia_live_ key. Immutable; safe to cache as an array.
 */
final class ServiceCaller
{
    public const REQUEST_ATTRIBUTE = 'service_caller';

    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public readonly string $serviceCode,
        public readonly ?string $application,
        public readonly array $scopes,
        public readonly ?string $tenantReference,
        public readonly string $ownerType = 'SERVICE_ACCOUNT',
    ) {}

    public function hasScope(string $scope): bool
    {
        foreach ($this->scopes as $granted) {
            if ($granted === '*' || $granted === $scope) {
                return true;
            }

            if (str_ends_with($granted, ':*') && str_starts_with($scope, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload  identity's /api-key/introspect body
     */
    public static function fromIntrospection(array $payload): self
    {
        $owner = $payload['owner'] ?? [];
        $ownerType = (string) ($owner['type'] ?? 'SERVICE_ACCOUNT');

        $serviceCode = $ownerType === 'USER'
            ? 'user:'.($owner['public_id'] ?? 'unknown')
            : (string) ($owner['service_code'] ?? 'unknown');

        return new self(
            serviceCode: $serviceCode,
            application: isset($payload['application']) ? (string) $payload['application'] : null,
            scopes: array_values(array_map('strval', $payload['scopes'] ?? [])),
            tenantReference: isset($payload['tenant_reference']) ? (string) $payload['tenant_reference'] : null,
            ownerType: $ownerType,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'service_code' => $this->serviceCode,
            'application' => $this->application,
            'scopes' => $this->scopes,
            'tenant_reference' => $this->tenantReference,
            'owner_type' => $this->ownerType,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['service_code'],
            $data['application'] ?? null,
            $data['scopes'] ?? [],
            $data['tenant_reference'] ?? null,
            $data['owner_type'] ?? 'SERVICE_ACCOUNT',
        );
    }

    public static function fromRequest(Request $request): ?self
    {
        $caller = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $caller instanceof self ? $caller : null;
    }
}
```

`src/Auth/Introspector.php`:

```php
<?php

namespace Enadstack\ApiContracts\Auth;

use Enadstack\ApiContracts\Exceptions\IdentityUnavailableException;

interface Introspector
{
    /**
     * Resolve an ia_live_ token to its caller. Null means the token is
     * unknown, revoked or expired.
     *
     * @throws IdentityUnavailableException when identity cannot answer
     */
    public function introspect(string $token): ?ServiceCaller;
}
```

- [ ] **Step 5: Implement `HttpIntrospector`**

`src/Auth/HttpIntrospector.php`:

```php
<?php

namespace Enadstack\ApiContracts\Auth;

use Enadstack\ApiContracts\Exceptions\IdentityUnavailableException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpIntrospector implements Introspector
{
    private const INACTIVE = '__inactive__';

    /**
     * @param  array{identity_url: string, cache_ttl: int, negative_ttl: int, timeout: int}  $config
     */
    public function __construct(
        private readonly Cache $cache,
        private readonly array $config,
    ) {}

    public function introspect(string $token): ?ServiceCaller
    {
        $key = 'svc-auth:'.hash('sha256', $token);

        $cached = $this->cache->get($key);
        if ($cached === self::INACTIVE) {
            return null;
        }
        if (is_array($cached)) {
            return ServiceCaller::fromArray($cached);
        }

        try {
            $response = Http::baseUrl(rtrim($this->config['identity_url'], '/'))
                ->acceptJson()
                ->timeout((int) $this->config['timeout'])
                ->withToken($token)
                ->get('/api/v1/api-key/introspect');
        } catch (ConnectionException $e) {
            throw new IdentityUnavailableException(previous: $e);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            $this->cache->put($key, self::INACTIVE, (int) $this->config['negative_ttl']);

            return null;
        }

        if (! $response->successful() || $response->json('active') !== true) {
            throw new IdentityUnavailableException("Identity introspection returned HTTP {$response->status()}.");
        }

        $caller = ServiceCaller::fromIntrospection($response->json());
        $this->cache->put($key, $caller->toArray(), (int) $this->config['cache_ttl']);

        return $caller;
    }
}
```

- [ ] **Step 6: Implement the fake and the `ServiceAuth` helper**

`src/Auth/FakeIntrospector.php`:

```php
<?php

namespace Enadstack\ApiContracts\Auth;

use Enadstack\ApiContracts\Exceptions\IdentityUnavailableException;

class FakeIntrospector implements Introspector
{
    /** @var array<int, string> */
    private array $rejected = [];

    private bool $unavailable = false;

    public function __construct(private ServiceCaller $caller) {}

    public function rejecting(string ...$tokens): static
    {
        $this->rejected = array_merge($this->rejected, $tokens);

        return $this;
    }

    public function unavailable(): static
    {
        $this->unavailable = true;

        return $this;
    }

    public function as(ServiceCaller $caller): static
    {
        $this->caller = $caller;

        return $this;
    }

    public function introspect(string $token): ?ServiceCaller
    {
        if ($this->unavailable) {
            throw new IdentityUnavailableException;
        }

        if (in_array($token, $this->rejected, true)) {
            return null;
        }

        return $this->caller;
    }
}
```

`src/Testing/ServiceAuth.php`:

```php
<?php

namespace Enadstack\ApiContracts\Testing;

use Enadstack\ApiContracts\Auth\FakeIntrospector;
use Enadstack\ApiContracts\Auth\Introspector;
use Enadstack\ApiContracts\Auth\ServiceCaller;

/**
 * Test helper: swap identity introspection for an in-memory fake so service
 * tests never make HTTP calls. Send ServiceAuth::TEST_TOKEN as the bearer.
 */
final class ServiceAuth
{
    public const TEST_TOKEN = 'ia_live_tttttttttttttttttttttttttttttttttttttttt';

    /**
     * @param  array<int, string>  $scopes
     */
    public static function fake(
        string $serviceCode = 'test-service',
        ?string $application = 'test-app',
        array $scopes = ['*'],
        ?string $tenantReference = null,
    ): FakeIntrospector {
        $fake = new FakeIntrospector(new ServiceCaller($serviceCode, $application, $scopes, $tenantReference));

        app()->instance(Introspector::class, $fake);

        return $fake;
    }

    /** @return array<string, string> */
    public static function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::TEST_TOKEN];
    }
}
```

- [ ] **Step 7: Config and container binding**

Append to the array in `config/api-contracts.php` (after the `error` block):

```php
    /*
    |--------------------------------------------------------------------------
    | Service Authentication
    |--------------------------------------------------------------------------
    |
    | How the `service.auth` middleware validates ia_live_ bearer keys: it asks
    | identity-access-service's introspect endpoint and caches the answer.
    | Positive answers live for cache_ttl seconds; unknown/revoked keys are
    | remembered for negative_ttl seconds so a bad key cannot hammer identity.
    */
    'service_auth' => [
        'identity_url' => env('IDENTITY_SERVICE_URL', 'http://identity-access-service.test'),
        'cache_store' => env('SERVICE_AUTH_CACHE_STORE'),
        'cache_ttl' => (int) env('SERVICE_AUTH_CACHE_TTL', 300),
        'negative_ttl' => (int) env('SERVICE_AUTH_NEGATIVE_TTL', 60),
        'timeout' => (int) env('SERVICE_AUTH_TIMEOUT', 5),
    ],
```

In `src/ApiContractsServiceProvider.php`, add imports and the binding inside `register()` after `mergeConfigFrom`:

```php
use Enadstack\ApiContracts\Auth\HttpIntrospector;
use Enadstack\ApiContracts\Auth\Introspector;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
```

```php
        $this->app->bind(Introspector::class, function ($app) {
            $config = $app['config']->get('api-contracts.service_auth');

            return new HttpIntrospector(
                $app->make(CacheFactory::class)->store($config['cache_store'] ?? null),
                $config,
            );
        });
```

- [ ] **Step 8: Run the tests**

Run: `vendor/bin/phpunit tests/Unit/ServiceCallerTest.php tests/Unit/HttpIntrospectorTest.php`
Expected: 10 tests pass.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add src/Auth src/Testing src/Exceptions/IdentityUnavailableException.php config/api-contracts.php src/ApiContractsServiceProvider.php tests/Unit/ServiceCallerTest.php tests/Unit/HttpIntrospectorTest.php
git commit -m "feat: service caller resolution via identity introspection with cache and test fake

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 3: `service.auth` and `service.scope` middleware

**Files:**
- Create: `src/Http/Middleware/AuthenticateServiceKey.php`
- Create: `src/Http/Middleware/RequireScope.php`
- Create: `src/Exceptions/MissingScopeException.php`
- Modify: `src/ApiContractsServiceProvider.php` (alias registration in `boot()`)
- Test: `tests/Feature/ServiceAuthMiddlewareTest.php`

**Interfaces:**
- Consumes: `Introspector`, `ServiceCaller`, `ServiceAuth::fake()`, `ApiExceptionRenderer` (renders `AuthenticationException` → 401 `UNAUTHORIZED`, `HasErrorCode` → its code/status).
- Produces: aliases `service.auth` and `service.scope:{scope}`; request attribute `service_caller`; `Context` key `service_code`; `MissingScopeException` (`FORBIDDEN`, 403, `details.required_scope`).

- [ ] **Step 1: Write the failing feature test**

`tests/Feature/ServiceAuthMiddlewareTest.php`:

```php
<?php

namespace Enadstack\ApiContracts\Tests\Feature;

use Enadstack\ApiContracts\Auth\ServiceCaller;
use Enadstack\ApiContracts\Http\Exceptions\ApiExceptionRenderer;
use Enadstack\ApiContracts\Testing\ServiceAuth;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

class ServiceAuthMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware(['api', 'service.auth'])->get('/api/whoami', function (Request $request) {
            return ['service_code' => ServiceCaller::fromRequest($request)->serviceCode, 'context' => Context::get('service_code')];
        });

        $router->middleware(['api', 'service.auth', 'service.scope:notifications:send'])->post('/api/send', fn () => ['ok' => true]);
    }

    protected function resolveApplicationExceptionHandler($app): void
    {
        parent::resolveApplicationExceptionHandler($app);

        $app->afterResolving(\Illuminate\Foundation\Configuration\Exceptions::class, fn ($e) => ApiExceptionRenderer::register($e));
    }

    public function test_a_request_without_a_bearer_token_is_401(): void
    {
        ServiceAuth::fake();

        $this->getJson('/api/whoami')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_a_token_without_the_ia_live_prefix_is_401_without_calling_identity(): void
    {
        ServiceAuth::fake()->unavailable();

        $this->withToken('not-a-key')->getJson('/api/whoami')->assertStatus(401);
    }

    public function test_a_rejected_token_is_401(): void
    {
        ServiceAuth::fake()->rejecting(ServiceAuth::TEST_TOKEN);

        $this->withToken(ServiceAuth::TEST_TOKEN)->getJson('/api/whoami')->assertStatus(401);
    }

    public function test_a_valid_token_resolves_the_caller_onto_the_request_and_context(): void
    {
        ServiceAuth::fake(serviceCode: 'sofrito-app');

        $this->withToken(ServiceAuth::TEST_TOKEN)->getJson('/api/whoami')
            ->assertOk()
            ->assertJson(['service_code' => 'sofrito-app', 'context' => 'sofrito-app']);
    }

    public function test_identity_being_down_is_a_503(): void
    {
        ServiceAuth::fake()->unavailable();

        $this->withToken(ServiceAuth::TEST_TOKEN)->getJson('/api/whoami')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'IDENTITY_UNAVAILABLE');
    }

    public function test_a_missing_scope_is_403_with_the_required_scope_in_details(): void
    {
        ServiceAuth::fake(scopes: ['reviews:read']);

        $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/send')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.details.required_scope', 'notifications:send');
    }

    public function test_a_wildcard_scope_satisfies_the_requirement(): void
    {
        ServiceAuth::fake(scopes: ['notifications:*']);

        $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/send')->assertOk();
    }
}
```

Note for the implementer: Testbench's `resolveApplicationExceptionHandler` hook may differ by version. If the override above does not register the renderer (the 401 comes back without an `error.code`), instead register it in `defineEnvironment` using Testbench's `$this->app->make(\Illuminate\Foundation\Configuration\Exceptions::class)` if available, or assert only on status codes and add a comment. Status codes are the contract; the envelope is verified in `ApiExceptionRendererTest`.

- [ ] **Step 2: Run to confirm failure**

Run: `vendor/bin/phpunit tests/Feature/ServiceAuthMiddlewareTest.php`
Expected: routes 500 or middleware alias not found.

- [ ] **Step 3: Implement the exception**

`src/Exceptions/MissingScopeException.php`:

```php
<?php

namespace Enadstack\ApiContracts\Exceptions;

use Enadstack\ApiContracts\Contracts\HasErrorCode;
use RuntimeException;

class MissingScopeException extends RuntimeException implements HasErrorCode
{
    public function __construct(private readonly string $requiredScope)
    {
        parent::__construct("This action requires the [{$requiredScope}] scope.");
    }

    public function errorCode(): string
    {
        return 'FORBIDDEN';
    }

    /** @return array<string, mixed> */
    public function errorDetails(): array
    {
        return ['required_scope' => $this->requiredScope];
    }

    public function errorStatus(): int
    {
        return 403;
    }
}
```

- [ ] **Step 4: Implement the middleware**

`src/Http/Middleware/AuthenticateServiceKey.php`:

```php
<?php

namespace Enadstack\ApiContracts\Http\Middleware;

use Closure;
use Enadstack\ApiContracts\Auth\Introspector;
use Enadstack\ApiContracts\Auth\ServiceCaller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: service.auth. Authenticates the calling system from an
 * identity-issued ia_live_ bearer key. Fails closed.
 */
class AuthenticateServiceKey
{
    public function __construct(private readonly Introspector $introspector) {}

    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token || ! str_starts_with($token, 'ia_live_')) {
            throw new AuthenticationException('A service API key is required.');
        }

        $caller = $this->introspector->introspect($token);

        if ($caller === null) {
            throw new AuthenticationException('The service API key is invalid or revoked.');
        }

        $request->attributes->set(ServiceCaller::REQUEST_ATTRIBUTE, $caller);
        Context::add('service_code', $caller->serviceCode);
        if ($caller->application !== null) {
            Context::add('application', $caller->application);
        }

        return $next($request);
    }
}
```

`src/Http/Middleware/RequireScope.php`:

```php
<?php

namespace Enadstack\ApiContracts\Http\Middleware;

use Closure;
use Enadstack\ApiContracts\Auth\ServiceCaller;
use Enadstack\ApiContracts\Exceptions\MissingScopeException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: service.scope:{scope}. Must run after service.auth. Laravel splits
 * middleware parameters on the first colon only, so "service.scope:reviews:read"
 * passes "reviews:read" as the single parameter.
 */
class RequireScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $caller = ServiceCaller::fromRequest($request);

        if ($caller === null) {
            throw new AuthenticationException('service.scope requires service.auth to run first.');
        }

        foreach ($scopes as $scope) {
            if (! $caller->hasScope($scope)) {
                throw new MissingScopeException($scope);
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 5: Register the aliases**

In `src/ApiContractsServiceProvider.php` add imports:

```php
use Enadstack\ApiContracts\Http\Middleware\AuthenticateServiceKey;
use Enadstack\ApiContracts\Http\Middleware\RequireScope;
use Illuminate\Routing\Router;
```

and in `boot()`, before the `runningInConsole` block:

```php
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('service.auth', AuthenticateServiceKey::class);
        $router->aliasMiddleware('service.scope', RequireScope::class);
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit tests/Feature/ServiceAuthMiddlewareTest.php`
Expected: 7 tests pass (see the note under Step 1 if the envelope assertions fail while statuses are right).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add src/Http/Middleware/AuthenticateServiceKey.php src/Http/Middleware/RequireScope.php src/Exceptions/MissingScopeException.php src/ApiContractsServiceProvider.php tests/Feature/ServiceAuthMiddlewareTest.php
git commit -m "feat: service.auth and service.scope middleware

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 4: `tenant.context` middleware and `HasTenantReference` model trait

**Files:**
- Create: `src/Http/Middleware/ResolveTenantContext.php`
- Create: `src/Exceptions/InvalidTenantReferenceException.php`
- Create: `src/Models/Concerns/HasTenantReference.php`
- Create: `src/Models/Scopes/TenantReferenceScope.php`
- Modify: `src/ApiContractsServiceProvider.php` (alias `tenant.context`)
- Test: `tests/Feature/TenantContextMiddlewareTest.php`
- Test: `tests/Feature/HasTenantReferenceTest.php`

**Interfaces:**
- Consumes: `ServiceCaller::fromRequest`, `Reference`, `Context`.
- Produces: alias `tenant.context`; `Context::get('tenant_reference')` and request attribute `tenant_reference` (string); `HasTenantReference` trait (global scope + fill on create + `static::withoutTenantScope()` query helper); `InvalidTenantReferenceException` (`INVALID_TENANT_REFERENCE`, 422, `details.reason` one of `missing`, `malformed`, `wrong_type`, `foreign_application`).

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TenantContextMiddlewareTest.php`:

```php
<?php

namespace Enadstack\ApiContracts\Tests\Feature;

use Enadstack\ApiContracts\Http\Exceptions\ApiExceptionRenderer;
use Enadstack\ApiContracts\Testing\ServiceAuth;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

class TenantContextMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware(['api', 'service.auth', 'tenant.context'])->get('/api/tenant', function (Request $request) {
            return ['attribute' => $request->attributes->get('tenant_reference'), 'context' => Context::get('tenant_reference')];
        });
    }

    protected function resolveApplicationExceptionHandler($app): void
    {
        parent::resolveApplicationExceptionHandler($app);

        $app->afterResolving(\Illuminate\Foundation\Configuration\Exceptions::class, fn ($e) => ApiExceptionRenderer::register($e));
    }

    public function test_a_tenant_scoped_key_sets_the_context_from_the_key(): void
    {
        ServiceAuth::fake(application: 'sofrito', tenantReference: 'sofrito:tenant:5');

        $this->withToken(ServiceAuth::TEST_TOKEN)
            ->withHeader('X-Tenant-Reference', 'sofrito:tenant:999')
            ->getJson('/api/tenant')
            ->assertOk()
            ->assertJson(['attribute' => 'sofrito:tenant:5', 'context' => 'sofrito:tenant:5']);
    }

    public function test_a_platform_key_takes_the_tenant_from_the_header(): void
    {
        ServiceAuth::fake(application: 'sofrito');

        $this->withToken(ServiceAuth::TEST_TOKEN)
            ->withHeader('X-Tenant-Reference', 'sofrito:tenant:7')
            ->getJson('/api/tenant')
            ->assertOk()
            ->assertJson(['context' => 'sofrito:tenant:7']);
    }

    public function test_a_platform_key_without_the_header_is_422(): void
    {
        ServiceAuth::fake(application: 'sofrito');

        $this->withToken(ServiceAuth::TEST_TOKEN)->getJson('/api/tenant')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_TENANT_REFERENCE')
            ->assertJsonPath('error.details.reason', 'missing');
    }

    public function test_a_header_for_another_application_is_rejected(): void
    {
        ServiceAuth::fake(application: 'sofrito');

        $this->withToken(ServiceAuth::TEST_TOKEN)
            ->withHeader('X-Tenant-Reference', 'tabibihub:tenant:7')
            ->getJson('/api/tenant')
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'foreign_application');
    }

    public function test_a_non_tenant_reference_is_rejected(): void
    {
        ServiceAuth::fake(application: 'sofrito');

        $this->withToken(ServiceAuth::TEST_TOKEN)
            ->withHeader('X-Tenant-Reference', 'sofrito:user:7')
            ->getJson('/api/tenant')
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'wrong_type');
    }

    public function test_a_malformed_header_is_rejected(): void
    {
        ServiceAuth::fake(application: 'sofrito');

        $this->withToken(ServiceAuth::TEST_TOKEN)
            ->withHeader('X-Tenant-Reference', '42')
            ->getJson('/api/tenant')
            ->assertStatus(422)
            ->assertJsonPath('error.details.reason', 'malformed');
    }

    public function test_a_key_with_no_application_accepts_any_application_in_the_header(): void
    {
        ServiceAuth::fake(application: null);

        $this->withToken(ServiceAuth::TEST_TOKEN)
            ->withHeader('X-Tenant-Reference', 'tabibihub:tenant:7')
            ->getJson('/api/tenant')
            ->assertOk();
    }
}
```

`tests/Feature/HasTenantReferenceTest.php`:

```php
<?php

namespace Enadstack\ApiContracts\Tests\Feature;

use Enadstack\ApiContracts\Models\Concerns\HasTenantReference;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Schema;

class Widget extends Model
{
    use HasTenantReference;

    protected $guarded = [];
}

class HasTenantReferenceTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_reference')->nullable()->index();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_queries_are_scoped_to_the_context_tenant(): void
    {
        Widget::create(['tenant_reference' => 'sofrito:tenant:1', 'name' => 'a']);
        Widget::create(['tenant_reference' => 'sofrito:tenant:2', 'name' => 'b']);

        Context::add('tenant_reference', 'sofrito:tenant:1');

        $this->assertSame(['a'], Widget::pluck('name')->all());
        $this->assertSame(['a', 'b'], Widget::withoutTenantScope()->pluck('name')->all());
    }

    public function test_without_a_context_tenant_nothing_is_filtered(): void
    {
        Widget::create(['tenant_reference' => 'sofrito:tenant:1', 'name' => 'a']);
        Widget::create(['tenant_reference' => 'sofrito:tenant:2', 'name' => 'b']);

        $this->assertCount(2, Widget::all());
    }

    public function test_creating_fills_the_tenant_reference_from_context(): void
    {
        Context::add('tenant_reference', 'sofrito:tenant:9');

        $widget = Widget::create(['name' => 'x']);

        $this->assertSame('sofrito:tenant:9', $widget->fresh()->tenant_reference);
    }

    public function test_an_explicit_tenant_reference_is_not_overwritten(): void
    {
        Context::add('tenant_reference', 'sofrito:tenant:9');

        $widget = Widget::withoutTenantScope()->create(['name' => 'x', 'tenant_reference' => 'sofrito:tenant:3']);

        $this->assertSame('sofrito:tenant:3', $widget->tenant_reference);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `vendor/bin/phpunit tests/Feature/TenantContextMiddlewareTest.php tests/Feature/HasTenantReferenceTest.php`
Expected: class/alias not found.

- [ ] **Step 3: Implement the exception**

`src/Exceptions/InvalidTenantReferenceException.php`:

```php
<?php

namespace Enadstack\ApiContracts\Exceptions;

use Enadstack\ApiContracts\Contracts\HasErrorCode;
use RuntimeException;

class InvalidTenantReferenceException extends RuntimeException implements HasErrorCode
{
    /**
     * @param  'missing'|'malformed'|'wrong_type'|'foreign_application'  $reason
     */
    public function __construct(private readonly string $reason, private readonly ?string $value = null)
    {
        parent::__construct(match ($reason) {
            'missing' => 'An X-Tenant-Reference header is required for this request.',
            'malformed' => 'The X-Tenant-Reference header must be in the form application:tenant:id.',
            'wrong_type' => 'The X-Tenant-Reference header must be a tenant reference.',
            'foreign_application' => 'The X-Tenant-Reference header belongs to another application.',
        });
    }

    public function errorCode(): string
    {
        return 'INVALID_TENANT_REFERENCE';
    }

    /** @return array<string, mixed> */
    public function errorDetails(): array
    {
        return array_filter(['reason' => $this->reason, 'value' => $this->value], fn ($v) => $v !== null);
    }

    public function errorStatus(): int
    {
        return 422;
    }
}
```

- [ ] **Step 4: Implement the middleware**

`src/Http/Middleware/ResolveTenantContext.php`:

```php
<?php

namespace Enadstack\ApiContracts\Http\Middleware;

use Closure;
use Enadstack\ApiContracts\Auth\ServiceCaller;
use Enadstack\ApiContracts\Exceptions\InvalidTenantReferenceException;
use Enadstack\ApiContracts\Support\Reference;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: tenant.context. Must run after service.auth. A tenant-scoped key
 * fixes the tenant; a platform key must say which tenant it acts for via
 * X-Tenant-Reference, and only within its own application.
 */
class ResolveTenantContext
{
    public const HEADER = 'X-Tenant-Reference';

    public const CONTEXT_KEY = 'tenant_reference';

    public function handle(Request $request, Closure $next): Response
    {
        $caller = ServiceCaller::fromRequest($request);

        if ($caller === null) {
            throw new AuthenticationException('tenant.context requires service.auth to run first.');
        }

        $reference = $caller->tenantReference !== null
            ? Reference::parse($caller->tenantReference)
            : $this->fromHeader($request, $caller);

        $request->attributes->set(self::CONTEXT_KEY, (string) $reference);
        Context::add(self::CONTEXT_KEY, (string) $reference);

        return $next($request);
    }

    private function fromHeader(Request $request, ServiceCaller $caller): Reference
    {
        $raw = $request->header(self::HEADER);

        if ($raw === null || $raw === '') {
            throw new InvalidTenantReferenceException('missing');
        }

        $reference = Reference::tryParse($raw);

        if ($reference === null) {
            throw new InvalidTenantReferenceException('malformed', $raw);
        }

        if (! $reference->is('tenant')) {
            throw new InvalidTenantReferenceException('wrong_type', $raw);
        }

        if ($caller->application !== null && ! $reference->belongsTo($caller->application)) {
            throw new InvalidTenantReferenceException('foreign_application', $raw);
        }

        return $reference;
    }
}
```

- [ ] **Step 5: Implement the scope and trait**

`src/Models/Scopes/TenantReferenceScope.php`:

```php
<?php

namespace Enadstack\ApiContracts\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Context;

class TenantReferenceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Context::has('tenant_reference')) {
            $builder->where($model->qualifyColumn('tenant_reference'), Context::get('tenant_reference'));
        }
    }
}
```

`src/Models/Concerns/HasTenantReference.php`:

```php
<?php

namespace Enadstack\ApiContracts\Models\Concerns;

use Enadstack\ApiContracts\Models\Scopes\TenantReferenceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Context;

/**
 * Scopes every query to Context('tenant_reference') when one is set, and
 * stamps it onto new rows. Use static::withoutTenantScope() for platform
 * jobs that must see every tenant.
 */
trait HasTenantReference
{
    public static function bootHasTenantReference(): void
    {
        static::addGlobalScope(new TenantReferenceScope);

        static::creating(function ($model): void {
            if (empty($model->tenant_reference) && Context::has('tenant_reference')) {
                $model->tenant_reference = Context::get('tenant_reference');
            }
        });
    }

    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope(TenantReferenceScope::class);
    }
}
```

- [ ] **Step 6: Register the alias**

In `src/ApiContractsServiceProvider.php` add the import `use Enadstack\ApiContracts\Http\Middleware\ResolveTenantContext;` and, next to the other aliases:

```php
        $router->aliasMiddleware('tenant.context', ResolveTenantContext::class);
```

- [ ] **Step 7: Run the tests**

Run: `vendor/bin/phpunit tests/Feature/TenantContextMiddlewareTest.php tests/Feature/HasTenantReferenceTest.php`
Expected: 11 tests pass.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty
git add src/Http/Middleware/ResolveTenantContext.php src/Exceptions/InvalidTenantReferenceException.php src/Models src/ApiContractsServiceProvider.php tests/Feature/TenantContextMiddlewareTest.php tests/Feature/HasTenantReferenceTest.php
git commit -m "feat: tenant.context middleware and HasTenantReference model trait

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 5: `idempotency` middleware

**Files:**
- Create: `src/Http/Middleware/Idempotency.php`
- Modify: `config/api-contracts.php` (add `idempotency` block)
- Modify: `src/ApiContractsServiceProvider.php` (alias)
- Test: `tests/Feature/IdempotencyMiddlewareTest.php`

**Interfaces:**
- Consumes: `ServiceCaller::fromRequest` (optional; falls back to client IP), cache repository.
- Produces: alias `idempotency`; replays cached responses for the same caller + method + path + `Idempotency-Key` within `api-contracts.idempotency.ttl` seconds, adding header `Idempotent-Replayed: true`. Only `POST`, `PUT`, `PATCH` are considered; 5xx responses are not stored.

- [ ] **Step 1: Write the failing test**

`tests/Feature/IdempotencyMiddlewareTest.php`:

```php
<?php

namespace Enadstack\ApiContracts\Tests\Feature;

use Enadstack\ApiContracts\Testing\ServiceAuth;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Support\Str;

class IdempotencyMiddlewareTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function defineRoutes($router): void
    {
        $router->middleware(['api', 'service.auth', 'idempotency'])->post('/api/things', fn () => response()->json(['id' => Str::random(8)], 201));
        $router->middleware(['api', 'service.auth', 'idempotency'])->post('/api/boom', fn () => response()->json(['error' => 'x'], 500));
    }

    public function test_the_same_key_replays_the_first_response(): void
    {
        ServiceAuth::fake();
        $headers = ['Idempotency-Key' => 'abc-1'];

        $first = $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/things', [], $headers);
        $second = $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/things', [], $headers);

        $first->assertStatus(201)->assertHeaderMissing('Idempotent-Replayed');
        $second->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('id'), $second->json('id'));
    }

    public function test_different_keys_are_different_requests(): void
    {
        ServiceAuth::fake();

        $a = $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/things', [], ['Idempotency-Key' => 'k1'])->json('id');
        $b = $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/things', [], ['Idempotency-Key' => 'k2'])->json('id');

        $this->assertNotSame($a, $b);
    }

    public function test_keys_are_scoped_per_caller(): void
    {
        ServiceAuth::fake(serviceCode: 'one');
        $a = $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/things', [], ['Idempotency-Key' => 'same'])->json('id');

        ServiceAuth::fake(serviceCode: 'two');
        $b = $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/things', [], ['Idempotency-Key' => 'same'])->json('id');

        $this->assertNotSame($a, $b);
    }

    public function test_without_a_key_nothing_is_cached(): void
    {
        ServiceAuth::fake();

        $a = $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/things')->json('id');
        $b = $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/things')->json('id');

        $this->assertNotSame($a, $b);
    }

    public function test_server_errors_are_not_replayed(): void
    {
        ServiceAuth::fake();

        $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/boom', [], ['Idempotency-Key' => 'e1'])->assertStatus(500);
        $this->withToken(ServiceAuth::TEST_TOKEN)->postJson('/api/boom', [], ['Idempotency-Key' => 'e1'])
            ->assertStatus(500)
            ->assertHeaderMissing('Idempotent-Replayed');
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `vendor/bin/phpunit tests/Feature/IdempotencyMiddlewareTest.php`
Expected: alias not found.

- [ ] **Step 3: Implement**

`src/Http/Middleware/Idempotency.php`:

```php
<?php

namespace Enadstack\ApiContracts\Http\Middleware;

use Closure;
use Enadstack\ApiContracts\Auth\ServiceCaller;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: idempotency. Replays the stored response for a repeated
 * Idempotency-Key from the same caller on the same method + path.
 */
class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(private readonly Cache $cache) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if (! $key || ! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $cacheKey = $this->cacheKey($request, $key);

        $stored = $this->cache->get($cacheKey);
        if (is_array($stored)) {
            return response($stored['body'], $stored['status'], $stored['headers'])
                ->header('Idempotent-Replayed', 'true');
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $this->cache->put($cacheKey, [
                'status' => $response->getStatusCode(),
                'headers' => ['Content-Type' => $response->headers->get('Content-Type', 'application/json')],
                'body' => $response->getContent(),
            ], (int) config('api-contracts.idempotency.ttl', 86400));
        }

        return $response;
    }

    private function cacheKey(Request $request, string $key): string
    {
        $scope = ServiceCaller::fromRequest($request)?->serviceCode ?? 'ip:'.$request->ip();

        return 'idempotency:'.sha1($scope.'|'.$request->method().'|'.$request->path().'|'.$key);
    }
}
```

Config block appended to `config/api-contracts.php`:

```php
    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    */
    'idempotency' => [
        'ttl' => (int) env('IDEMPOTENCY_TTL', 86400),
    ],
```

Provider: import `use Enadstack\ApiContracts\Http\Middleware\Idempotency;` and add `$router->aliasMiddleware('idempotency', Idempotency::class);`. The middleware needs a cache repository: add to `register()`:

```php
        $this->app->when(Idempotency::class)
            ->needs(\Illuminate\Contracts\Cache\Repository::class)
            ->give(fn ($app) => $app->make(CacheFactory::class)->store($app['config']->get('api-contracts.service_auth.cache_store')));
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Feature/IdempotencyMiddlewareTest.php`
Expected: 5 tests pass.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add src/Http/Middleware/Idempotency.php config/api-contracts.php src/ApiContractsServiceProvider.php tests/Feature/IdempotencyMiddlewareTest.php
git commit -m "feat: idempotency middleware

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 6: Outbox — model, migration, recorder, HTTP publisher, processor command

**Files:**
- Create: `database/migrations/2026_09_02_000000_create_outbox_events_table.php`
- Create: `src/Outbox/OutboxEvent.php`
- Create: `src/Outbox/RecordOutboxEvent.php`
- Create: `src/Outbox/Publisher.php`
- Create: `src/Outbox/HttpPublisher.php`
- Create: `src/Outbox/PublishFailedException.php`
- Create: `src/Console/ProcessOutboxCommand.php`
- Modify: `config/api-contracts.php` (add `outbox` block)
- Modify: `src/ApiContractsServiceProvider.php` (publish migration, bind `Publisher`, register command)
- Test: `tests/Feature/OutboxTest.php`

**Interfaces:**
- Consumes: `Context::get('tenant_reference')`, HTTP client.
- Produces:
  - table `outbox_events`: `id` uuid pk, `event_type` string, `event_version` unsignedSmallInteger default 1, `producer` string, `tenant_reference` string nullable index, `aggregate_type` string, `aggregate_id` string, `payload` json, `occurred_at` timestamp, `published_at` timestamp nullable, `attempts` unsignedInteger default 0, `next_attempt_at` timestamp nullable, `last_error` text nullable, `created_at`/`updated_at`; index `(published_at, next_attempt_at)`.
  - `OutboxEvent` Eloquent model (`HasUuids`), `envelope(): array` → `{id, type, version, producer, tenant_reference, aggregate: {type, id}, occurred_at (ISO-8601), payload}`.
  - `RecordOutboxEvent::__invoke(string $type, string $aggregateType, string|int $aggregateId, array $payload, int $version = 1): OutboxEvent`.
  - `Publisher::publish(OutboxEvent $event): void` (throws `PublishFailedException`).
  - `HttpPublisher` posts `envelope()` as JSON to each URL in `api-contracts.outbox.subscribers[$type]` plus `subscribers['*']`, with headers `X-Event-Id`, `X-Event-Type`, `X-Signature: sha256=<hmac of body with outbox.signing_secret>`.
  - artisan `outbox:process {--limit=100}`; backoff `min(2^attempts, 60)` minutes.

- [ ] **Step 1: Write the failing test**

`tests/Feature/OutboxTest.php`:

```php
<?php

namespace Enadstack\ApiContracts\Tests\Feature;

use Enadstack\ApiContracts\Outbox\OutboxEvent;
use Enadstack\ApiContracts\Outbox\RecordOutboxEvent;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

class OutboxTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('api-contracts.outbox.producer', 'review-rating-service');
        $app['config']->set('api-contracts.outbox.signing_secret', 'top-secret');
        $app['config']->set('api-contracts.outbox.subscribers', [
            'review.published' => ['http://sofrito.test/hooks/reviews'],
            '*' => ['http://audit.test/all'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }

    public function test_recording_stamps_producer_tenant_and_occurred_at(): void
    {
        Context::add('tenant_reference', 'sofrito:tenant:3');

        $event = app(RecordOutboxEvent::class)('review.published', 'Review', 'r-1', ['rating' => 5]);

        $this->assertDatabaseHas('outbox_events', [
            'id' => $event->id,
            'event_type' => 'review.published',
            'producer' => 'review-rating-service',
            'tenant_reference' => 'sofrito:tenant:3',
            'aggregate_id' => 'r-1',
        ]);
        $this->assertSame(['rating' => 5], $event->envelope()['payload']);
        $this->assertNull($event->published_at);
    }

    public function test_processing_posts_signed_envelopes_to_type_and_wildcard_subscribers(): void
    {
        Http::fake();
        $event = app(RecordOutboxEvent::class)('review.published', 'Review', 'r-1', ['rating' => 5]);

        $this->artisan('outbox:process')->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($event) {
            $body = $request->body();

            return $request->url() === 'http://sofrito.test/hooks/reviews'
                && $request->hasHeader('X-Event-Id', $event->id)
                && $request->hasHeader('X-Event-Type', 'review.published')
                && $request->hasHeader('X-Signature', 'sha256='.hash_hmac('sha256', $body, 'top-secret'))
                && json_decode($body, true)['payload'] === ['rating' => 5];
        });
        $this->assertNotNull($event->fresh()->published_at);
    }

    public function test_a_failed_delivery_records_the_error_and_backs_off(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        $event = app(RecordOutboxEvent::class)('review.published', 'Review', 'r-1', []);

        $this->artisan('outbox:process')->assertSuccessful();

        $fresh = $event->fresh();
        $this->assertNull($fresh->published_at);
        $this->assertSame(1, $fresh->attempts);
        $this->assertStringContainsString('500', $fresh->last_error);
        $this->assertTrue($fresh->next_attempt_at->greaterThan(now()->addMinute()));
        $this->assertTrue($fresh->next_attempt_at->lessThanOrEqualTo(now()->addMinutes(2)->addSecond()));
    }

    public function test_events_waiting_for_their_next_attempt_are_skipped(): void
    {
        Http::fake();
        $event = app(RecordOutboxEvent::class)('review.published', 'Review', 'r-1', []);
        $event->update(['attempts' => 3, 'next_attempt_at' => now()->addHour()]);

        $this->artisan('outbox:process')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_an_event_type_with_no_subscribers_is_marked_published(): void
    {
        Http::fake();
        $this->app['config']->set('api-contracts.outbox.subscribers', []);
        $event = app(RecordOutboxEvent::class)('nobody.cares', 'X', '1', []);

        $this->artisan('outbox:process')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNotNull($event->fresh()->published_at);
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `vendor/bin/phpunit tests/Feature/OutboxTest.php`
Expected: class not found.

- [ ] **Step 3: Migration**

`database/migrations/2026_09_02_000000_create_outbox_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type')->index();
            $table->unsignedSmallInteger('event_version')->default(1);
            $table->string('producer');
            $table->string('tenant_reference')->nullable()->index();
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['published_at', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
```

- [ ] **Step 4: Model, recorder, publisher contract**

`src/Outbox/OutboxEvent.php`:

```php
<?php

namespace Enadstack\ApiContracts\Outbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasUuids;

    protected $table = 'outbox_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'attempts' => 'integer',
            'event_version' => 'integer',
        ];
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->whereNull('published_at')
            ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('occurred_at');
    }

    /** @return array<string, mixed> */
    public function envelope(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->event_type,
            'version' => $this->event_version,
            'producer' => $this->producer,
            'tenant_reference' => $this->tenant_reference,
            'aggregate' => ['type' => $this->aggregate_type, 'id' => $this->aggregate_id],
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'payload' => $this->payload ?? [],
        ];
    }
}
```

`src/Outbox/RecordOutboxEvent.php`:

```php
<?php

namespace Enadstack\ApiContracts\Outbox;

use Illuminate\Support\Facades\Context;

/**
 * Call inside the same database transaction as the state change it
 * describes, so the event exists if and only if the change committed.
 */
class RecordOutboxEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(string $type, string $aggregateType, string|int $aggregateId, array $payload, int $version = 1): OutboxEvent
    {
        return OutboxEvent::create([
            'event_type' => $type,
            'event_version' => $version,
            'producer' => (string) config('api-contracts.outbox.producer', config('app.name')),
            'tenant_reference' => Context::get('tenant_reference'),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
    }
}
```

`src/Outbox/Publisher.php`:

```php
<?php

namespace Enadstack\ApiContracts\Outbox;

interface Publisher
{
    /**
     * @throws PublishFailedException
     */
    public function publish(OutboxEvent $event): void;
}
```

`src/Outbox/PublishFailedException.php`:

```php
<?php

namespace Enadstack\ApiContracts\Outbox;

use RuntimeException;

class PublishFailedException extends RuntimeException {}
```

- [ ] **Step 5: HTTP publisher**

`src/Outbox/HttpPublisher.php`:

```php
<?php

namespace Enadstack\ApiContracts\Outbox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpPublisher implements Publisher
{
    public function publish(OutboxEvent $event): void
    {
        $subscribers = $this->subscribersFor($event->event_type);

        if ($subscribers === []) {
            return;
        }

        $body = json_encode($event->envelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, (string) config('api-contracts.outbox.signing_secret'));

        foreach ($subscribers as $url) {
            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Event-Id' => $event->id,
                    'X-Event-Type' => $event->event_type,
                    'X-Signature' => $signature,
                ])->timeout((int) config('api-contracts.outbox.timeout', 10))->withBody($body, 'application/json')->post($url);
            } catch (ConnectionException $e) {
                throw new PublishFailedException("Could not reach {$url}: {$e->getMessage()}", 0, $e);
            }

            if (! $response->successful()) {
                throw new PublishFailedException("{$url} responded with HTTP {$response->status()}.");
            }
        }
    }

    /** @return array<int, string> */
    private function subscribersFor(string $type): array
    {
        $all = (array) config('api-contracts.outbox.subscribers', []);

        return array_values(array_unique(array_merge((array) ($all[$type] ?? []), (array) ($all['*'] ?? []))));
    }
}
```

- [ ] **Step 6: Processor command**

`src/Console/ProcessOutboxCommand.php`:

```php
<?php

namespace Enadstack\ApiContracts\Console;

use Enadstack\ApiContracts\Outbox\OutboxEvent;
use Enadstack\ApiContracts\Outbox\Publisher;
use Illuminate\Console\Command;
use Throwable;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process {--limit=100 : Maximum events to deliver in this run}';

    protected $description = 'Deliver due outbox events to their HTTP subscribers with exponential backoff';

    public function handle(Publisher $publisher): int
    {
        $published = 0;
        $failed = 0;

        OutboxEvent::query()->due()->limit((int) $this->option('limit'))->get()->each(function (OutboxEvent $event) use ($publisher, &$published, &$failed): void {
            try {
                $publisher->publish($event);
                $event->update(['published_at' => now(), 'last_error' => null]);
                $published++;
            } catch (Throwable $e) {
                $attempts = $event->attempts + 1;
                $event->update([
                    'attempts' => $attempts,
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                    'next_attempt_at' => now()->addMinutes(min(2 ** $attempts, 60)),
                ]);
                $failed++;
            }
        });

        $this->info("Outbox: {$published} published, {$failed} failed.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 7: Config and provider wiring**

Append to `config/api-contracts.php`:

```php
    /*
    |--------------------------------------------------------------------------
    | Outbox
    |--------------------------------------------------------------------------
    |
    | Domain events are recorded in outbox_events inside the producing
    | transaction and delivered later by `outbox:process` to the HTTP
    | subscribers listed per event type ('*' receives everything).
    */
    'outbox' => [
        'producer' => env('OUTBOX_PRODUCER', env('APP_NAME', 'service')),
        'signing_secret' => env('OUTBOX_SIGNING_SECRET', ''),
        'timeout' => (int) env('OUTBOX_HTTP_TIMEOUT', 10),
        'subscribers' => [
            // 'review.published' => ['https://sofrito.example/hooks/reviews'],
        ],
    ],
```

Provider — imports:

```php
use Enadstack\ApiContracts\Console\ProcessOutboxCommand;
use Enadstack\ApiContracts\Outbox\HttpPublisher;
use Enadstack\ApiContracts\Outbox\Publisher;
```

In `register()`: `$this->app->bind(Publisher::class, HttpPublisher::class);`

In `boot()` inside the `runningInConsole` block:

```php
            $this->commands([ProcessOutboxCommand::class]);

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'api-contracts-migrations');
```

- [ ] **Step 8: Run the tests**

Run: `vendor/bin/phpunit tests/Feature/OutboxTest.php`
Expected: 5 tests pass.

- [ ] **Step 9: Commit**

```bash
vendor/bin/pint --dirty
git add database src/Outbox src/Console config/api-contracts.php src/ApiContractsServiceProvider.php tests/Feature/OutboxTest.php
git commit -m "feat: transactional outbox with signed HTTP delivery and backoff

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 7: Package constraints, `STANDARD.md`, README, release `v1.1.0`

**Files:**
- Modify: `composer.json`
- Modify: `.gitignore` (add `composer.lock`)
- Create: `STANDARD.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md` (create if absent)

**Interfaces:**
- Produces: git tag `v1.1.0` pushed to `origin`; `STANDARD.md` is the document every service `CLAUDE.md` will link to (plan 7).

- [ ] **Step 1: Widen the framework constraints**

In `composer.json` set:

```json
    "require": {
        "php": "^8.3",
        "illuminate/support": "^12.0|^13.0",
        "illuminate/http": "^12.0|^13.0",
        "illuminate/pagination": "^12.0|^13.0",
        "illuminate/database": "^12.0|^13.0",
        "illuminate/cache": "^12.0|^13.0",
        "illuminate/console": "^12.0|^13.0",
        "illuminate/validation": "^12.0|^13.0",
        "illuminate/contracts": "^12.0|^13.0"
    },
    "require-dev": {
        "orchestra/testbench": "^10.0|^11.0",
        "phpunit/phpunit": "^11.0|^12.0",
        "laravel/pint": "^1.27"
    },
```

Add `composer.lock` to `.gitignore` (libraries do not commit a lock file):

```bash
printf '/vendor\ncomposer.lock\n.phpunit.result.cache\n' >> .gitignore
```

- [ ] **Step 2: Resolve and run the whole suite**

Run: `composer update --no-interaction 2>&1 | tail -3 && vendor/bin/phpunit`
Expected: `OK (54 tests, ...)` — the exact count is the sum of Tasks 1–6 plus the pre-existing 16. Record the number.

- [ ] **Step 3: Write `STANDARD.md`**

```markdown
# Platform Standard for Reusable Services

Every reusable Laravel service in the Enadstack workspace follows this
document. It is enforced by `enadstack/laravel-api-contracts`; install the
package and use its middleware instead of writing your own.

## 1. Vocabulary

- **Application** — a product: `sofrito`, `tabibihub`, `muhamihub`, `souqna`.
  Registered in identity-access-service.
- **Reference** — `{application}:{type}:{id}`, e.g. `sofrito:tenant:42`.
  Services store references as strings and never own the referenced table.
  Use `Enadstack\ApiContracts\Support\Reference` and `Rules\ReferenceRule`.
- **Service account / key** — a calling system registered in identity; keys
  look like `ia_live_…`. Create one with
  `php artisan identity:service-account:create <code> --application=<app> --scope=<s>...`
  in identity-access-service.
- **Scope** — `{service}:{action}`, e.g. `notifications:send`,
  `reviews:moderate`. Wildcards `reviews:*` and `*` are honoured.

## 2. Inbound authentication

Every `/api/*` route is behind `service.auth`. The caller sends
`Authorization: Bearer ia_live_…`. The middleware asks identity's
`GET /api/v1/api-key/introspect`, caches the answer, and exposes
`ServiceCaller::fromRequest($request)`. Then:

- `service.scope:<scope>` on routes that need a specific scope.
- `tenant.context` on tenant-scoped routes: a tenant-scoped key fixes the
  tenant; a platform key must send `X-Tenant-Reference` for a tenant in its
  own application. The result is `Context::get('tenant_reference')`.
- `idempotency` on create endpoints; callers send `Idempotency-Key`.

Never read `X-Tenant-Reference`, `X-Scopes`, or any identity header
directly. Never keep a per-service shared secret.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->api(prepend: [\Enadstack\ApiContracts\Http\Middleware\AuthenticateServiceKey::class]);
})
->withExceptions(function (Exceptions $exceptions) {
    \Enadstack\ApiContracts\Http\Exceptions\ApiExceptionRenderer::register($exceptions);
})
```

## 3. Responses

- Success: flat JSON under the contracted key (`{"review": {...}}`).
- Message only: `{"message": "..."}`.
- Collections: `{"data": [...], "links": {...}, "meta": {...}}`.
- Errors: `{"error": {"code": "SCREAMING_SNAKE", "message": "...", "details": {}}}`;
  `details` is always an object.
- Controllers extend a base `Controller` that uses `ApiResponses`.
- Domain errors implement `HasErrorCode`.

Reserved codes: `UNAUTHORIZED` 401, `FORBIDDEN` 403 (`details.required_scope`
when a scope is missing), `NOT_FOUND` 404, `VALIDATION_ERROR` 422,
`INVALID_REFERENCE` 422, `INVALID_TENANT_REFERENCE` 422, `RATE_LIMITED` 429,
`IDENTITY_UNAVAILABLE` 503, `SERVER_ERROR` 500.

## 4. Data ownership and tenancy

- Own only your domain's tables. Everything else is a `*_reference` string
  column (`tenant_reference`, `merchant_reference`, `user_reference`, ...).
- Tenant-scoped models use `Enadstack\ApiContracts\Models\Concerns\HasTenantReference`.
- No `tenants` or `merchants` tables in a service. No integer `tenant_id`.

## 5. Events

- Record domain events with `RecordOutboxEvent` inside the transaction that
  changes state. Deliver with `php artisan outbox:process` on the scheduler
  (every minute). Publish the migration with
  `php artisan vendor:publish --tag=api-contracts-migrations`.
- Event names are `{aggregate}.{past-tense}` in lowercase: `review.published`.
- Envelope: `{id, type, version, producer, tenant_reference, aggregate, occurred_at, payload}`.
  Subscribers verify `X-Signature: sha256=<hmac>` with the shared
  `OUTBOX_SIGNING_SECRET`.

## 6. Layout

```
app/Http/Controllers/Api/V1/*Controller.php   thin: validate, call, respond
app/Http/Requests/*Request.php                validation only
app/Actions/*                                 one write path per class
app/Services/*                                reads, orchestration, adapters
app/Models, app/Enums, app/Console/Commands
docs/SERVICE.md                               owned tables, references, scopes, events
tests/Feature/ServiceAuthTest.php             401/403/tenant matrix
```

## 7. Testing

- `ServiceAuth::fake(serviceCode, application, scopes, tenantReference)` in
  `TestCase::setUp`, and send `ServiceAuth::TEST_TOKEN` as the bearer.
- Every service has `tests/Feature/ServiceAuthTest.php` covering: no token,
  wrong prefix, rejected key, missing scope, identity unavailable, and the
  tenant header matrix for at least one route.
- Suites run green on the service's own database engine before merge.

## 8. Repository hygiene

- No `fix_*.php` migration-rewrite scripts. Use `php artisan make:migration`.
- `CLAUDE.md` contains a "Project standard" section linking this file.
- Package versions are semver tags; apps consume via Composer VCS.
```

- [ ] **Step 4: Update README and CHANGELOG**

Replace the README's "Implementation Status" paragraph with:

```markdown
## Implementation Status

Released. See `STANDARD.md` for the platform standard this package enforces.
Ships: `ApiResponses`, `ApiExceptionRenderer`, `Reference` + `ReferenceRule`,
`service.auth` / `service.scope` / `tenant.context` / `idempotency`
middleware, `HasTenantReference`, transactional outbox with `outbox:process`,
and `Testing\ServiceAuth` for fakes.
```

Add a section after installation:

```markdown
## Middleware

| Alias | Purpose |
|---|---|
| `service.auth` | Authenticate `Authorization: Bearer ia_live_…` via identity introspection (cached). |
| `service.scope:<scope>` | Require a scope on the resolved caller. |
| `tenant.context` | Resolve `tenant_reference` into `Context` from the key or `X-Tenant-Reference`. |
| `idempotency` | Replay responses for repeated `Idempotency-Key`. |

Config: `php artisan vendor:publish --tag=api-contracts-config` and set
`IDENTITY_SERVICE_URL`. In tests call
`\Enadstack\ApiContracts\Testing\ServiceAuth::fake()`.
```

Create `CHANGELOG.md`:

```markdown
# Changelog

## v1.1.0 — 2026-09-02

- Support Laravel 12 and 13.
- `Support\Reference`, `Rules\ReferenceRule`, `INVALID_REFERENCE`.
- `service.auth`, `service.scope`, `tenant.context`, `idempotency` middleware.
- `Auth\ServiceCaller`, `Auth\Introspector` (HTTP + fake), `Testing\ServiceAuth`.
- `Models\Concerns\HasTenantReference`.
- Outbox: migration, `OutboxEvent`, `RecordOutboxEvent`, `HttpPublisher`, `outbox:process`.
- `STANDARD.md`.

## v1.0.0

- `ApiResponses`, `ApiExceptionRenderer`, `HasErrorCode`.
```

- [ ] **Step 5: Commit, tag, push**

```bash
vendor/bin/pint --dirty
git add composer.json .gitignore STANDARD.md README.md CHANGELOG.md
git commit -m "docs: platform STANDARD.md, Laravel 12/13 support, release notes for v1.1.0

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
git tag -a v1.1.0 -m "Contracts v2: references, service auth, tenant context, idempotency, outbox"
git push origin main --tags
```

Expected: push succeeds; `git tag` lists `v1` and `v1.1.0`.

---

### Task 8: Identity — applications, application-aware service accounts, richer introspection

**Files:**
- Modify: `/Users/enadabuzaid/Herd/identity-access-service/config/identity.php` (`applications` list)
- Create: `/Users/enadabuzaid/Herd/identity-access-service/database/migrations/2026_09_02_000001_add_application_id_to_service_accounts_table.php`
- Modify: `/Users/enadabuzaid/Herd/identity-access-service/app/Models/ServiceAccount.php`
- Modify: `/Users/enadabuzaid/Herd/identity-access-service/app/Actions/ServiceAccounts/CreateServiceAccountAction.php`
- Modify: `/Users/enadabuzaid/Herd/identity-access-service/app/Http/Requests/ServiceAccounts/CreateServiceAccountRequest.php`
- Modify: `/Users/enadabuzaid/Herd/identity-access-service/app/Http/Controllers/Api/V1/ApiKeyIntrospectionController.php`
- Test: `/Users/enadabuzaid/Herd/identity-access-service/tests/Feature/ApiKeys/ApiKeyIntrospectionTest.php`
- Test: `/Users/enadabuzaid/Herd/identity-access-service/tests/Feature/Console/SyncApplicationsCommandTest.php` (may need its expected count updated)

**Interfaces:**
- Consumes: `Application` model (`code`, `name`, `status`), `ServiceAccount` (`service_code`, `tenant_reference`, `merchant_reference`, `status`, `scopes`), `ApiKey` (`tenant_reference`, `scopes`), `CreateApiKeyAction::handle(User|ServiceAccount, array): array{api_key, plain_text_key}`.
- Produces: `service_accounts.application_id` nullable FK → `applications`; `ServiceAccount::application(): BelongsTo`; `CreateServiceAccountAction::handle` accepts `application` (code) and stores `application_id`; introspect response gains `application` (code or null) and `tenant_reference` (key's, else service account's, else null). Applications `tabibihub` and `muhamihub` exist.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ApiKeys/ApiKeyIntrospectionTest.php` (inside the class):

```php
    public function test_introspection_reports_the_service_accounts_application_and_tenant(): void
    {
        $application = \App\Models\Application::where('code', 'sofrito')->firstOrFail();
        $serviceAccount = ServiceAccount::create([
            'name' => 'Sofrito app', 'service_code' => 'sofrito-app', 'status' => 'ACTIVE',
            'application_id' => $application->id, 'tenant_reference' => 'sofrito:tenant:12',
        ]);
        $plainTextKey = app(CreateApiKeyAction::class)->handle($serviceAccount, ['name' => 'k', 'scopes' => ['reviews:*']])['plain_text_key'];

        $response = $this->withToken($plainTextKey)->getJson('/api/v1/api-key/introspect');

        $response->assertOk()
            ->assertJsonPath('application', 'sofrito')
            ->assertJsonPath('tenant_reference', 'sofrito:tenant:12')
            ->assertJsonPath('scopes', ['reviews:*']);
    }

    public function test_introspection_reports_null_application_for_user_keys_and_unbound_accounts(): void
    {
        $serviceAccount = ServiceAccount::create(['name' => 'Sync', 'service_code' => 'sync-2', 'status' => 'ACTIVE']);
        $plainTextKey = app(CreateApiKeyAction::class)->handle($serviceAccount, ['name' => 'k'])['plain_text_key'];

        $this->withToken($plainTextKey)->getJson('/api/v1/api-key/introspect')
            ->assertOk()
            ->assertJsonPath('application', null)
            ->assertJsonPath('tenant_reference', null);
    }

    public function test_the_four_products_are_registered_applications(): void
    {
        foreach (['souqna', 'sofrito', 'tabibihub', 'muhamihub'] as $code) {
            $this->assertDatabaseHas('applications', ['code' => $code, 'status' => 'ACTIVE']);
        }
    }
```

(`TestCase::setUp` already seeds `config('identity.applications')` into the table.)

Append to `tests/Feature/ServiceAccounts/ServiceAccountApiTest.php` a test that the API accepts an application code. First read that file's existing "admin creates a service account" test to copy its login helper, then add:

```php
    public function test_a_service_account_can_be_bound_to_an_application(): void
    {
        $token = $this->loginAsAdmin(); // use the file's existing helper name

        $response = $this->withToken($token)->postJson('/api/v1/service-accounts', [
            'name' => 'TabibiHub app', 'service_code' => 'tabibihub-app', 'application' => 'tabibihub', 'scopes' => ['notifications:send'],
        ]);

        $response->assertStatus(201)->assertJsonPath('service_account.application_id', \App\Models\Application::where('code', 'tabibihub')->value('id'));
    }
```

If the file's helper is named differently, use that name; do not add a second helper.

- [ ] **Step 2: Run to confirm failure**

Run: `cd /Users/enadabuzaid/Herd/identity-access-service && php artisan test --compact tests/Feature/ApiKeys/ApiKeyIntrospectionTest.php tests/Feature/ServiceAccounts/ServiceAccountApiTest.php`
Expected: the new tests fail (`application` missing from JSON / unknown column / application row missing).

- [ ] **Step 3: Register the two applications**

In `config/identity.php`, replace the `applications` array with:

```php
    'applications' => [
        ['code' => 'souqna', 'name' => 'Souqna'],
        ['code' => 'sofrito', 'name' => 'Sofrito'],
        ['code' => 'tabibihub', 'name' => 'TabibiHub'],
        ['code' => 'muhamihub', 'name' => 'MuhamiHub'],
    ],
```

- [ ] **Step 4: Migration and model**

`database/migrations/2026_09_02_000001_add_application_id_to_service_accounts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_accounts', function (Blueprint $table): void {
            $table->foreignId('application_id')->nullable()->after('service_code')->constrained('applications')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('application_id');
        });
    }
};
```

`app/Models/ServiceAccount.php` — add import `use Illuminate\Database\Eloquent\Relations\BelongsTo;` and the relation:

```php
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
```

- [ ] **Step 5: Action and request accept an application code**

`CreateServiceAccountAction::handle` — update the docblock to include `application?: ?string` and the create call:

```php
        $applicationId = isset($data['application'])
            ? Application::where('code', $data['application'])->value('id')
            : null;

        $serviceAccount = ServiceAccount::create([
            'name' => $data['name'],
            'service_code' => $data['service_code'],
            'application_id' => $applicationId,
            'tenant_reference' => $data['tenant_reference'] ?? null,
            'merchant_reference' => $data['merchant_reference'] ?? null,
            'status' => ServiceAccountStatus::ACTIVE,
            'scopes' => $data['scopes'] ?? [],
        ]);
```

Add `use App\Models\Application;` to the action.

`CreateServiceAccountRequest::rules()` — add the rule (keep every existing rule):

```php
            'application' => ['nullable', 'string', 'exists:applications,code'],
```

- [ ] **Step 6: Introspection returns application and tenant**

`ApiKeyIntrospectionController::show` — load the relation and add two keys. Replace the method body with:

```php
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');
        $apiKey->loadMissing('serviceAccount.application');

        return $this->successResponse([
            'active' => true,
            'api_key_id' => $apiKey->id,
            'scopes' => $apiKey->scopes ?? [],
            'expires_at' => $apiKey->expires_at,
            'application' => $apiKey->serviceAccount?->application?->code,
            'tenant_reference' => $apiKey->tenant_reference ?? $apiKey->serviceAccount?->tenant_reference,
            'owner' => $apiKey->user
                ? ['type' => 'USER', 'public_id' => $apiKey->user->public_id, 'email' => $apiKey->user->email]
                : ['type' => 'SERVICE_ACCOUNT', 'id' => $apiKey->serviceAccount->id, 'service_code' => $apiKey->serviceAccount->service_code],
        ]);
```

- [ ] **Step 7: Run the affected suites, then everything**

Run: `php artisan test --compact tests/Feature/ApiKeys tests/Feature/ServiceAccounts tests/Feature/Console tests/Feature/Applications`
Expected: all pass. If `SyncApplicationsCommandTest` asserts a literal `2`, change it to `count(config('identity.applications'))`.

Run: `php artisan test --compact`
Expected: 0 failures, 0 errors.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add config/identity.php database/migrations/2026_09_02_000001_add_application_id_to_service_accounts_table.php app/Models/ServiceAccount.php app/Actions/ServiceAccounts/CreateServiceAccountAction.php app/Http/Requests/ServiceAccounts/CreateServiceAccountRequest.php app/Http/Controllers/Api/V1/ApiKeyIntrospectionController.php tests/Feature/ApiKeys/ApiKeyIntrospectionTest.php tests/Feature/ServiceAccounts/ServiceAccountApiTest.php tests/Feature/Console/SyncApplicationsCommandTest.php
git commit -m "feat(identity): application-bound service accounts and richer key introspection

Registers tabibihub and muhamihub, lets a service account belong to an
application, and returns application + tenant_reference from
/api-key/introspect so consuming services can enforce tenant context
without trusting headers.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 9: Identity — `identity:service-account:create` command

**Files:**
- Create: `/Users/enadabuzaid/Herd/identity-access-service/app/Console/Commands/CreateServiceAccountCommand.php`
- Test: `/Users/enadabuzaid/Herd/identity-access-service/tests/Feature/Console/CreateServiceAccountCommandTest.php`

**Interfaces:**
- Consumes: `CreateServiceAccountAction::handle(array)`, `CreateApiKeyAction::handle(ServiceAccount, array)`, `Application`.
- Produces: `php artisan identity:service-account:create {service_code} {--name=} {--application=} {--scope=*} {--tenant=} {--expires=}` printing the plain key once on the last line.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Models\ApiKey;
use App\Models\ServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateServiceAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_account_and_prints_one_working_key(): void
    {
        $this->artisan('identity:service-account:create', [
            'service_code' => 'tabibihub-app',
            '--application' => 'tabibihub',
            '--scope' => ['notifications:send', 'reviews:*'],
            '--tenant' => 'tabibihub:tenant:01J',
        ])
            ->expectsOutputToContain('ia_live_')
            ->assertSuccessful();

        $account = ServiceAccount::where('service_code', 'tabibihub-app')->firstOrFail();
        $this->assertSame('tabibihub', $account->application->code);
        $this->assertSame('tabibihub:tenant:01J', $account->tenant_reference);
        $this->assertSame(['notifications:send', 'reviews:*'], $account->scopes);

        $key = ApiKey::where('service_account_id', $account->id)->firstOrFail();
        $this->assertSame(['notifications:send', 'reviews:*'], $key->scopes);
    }

    public function test_the_printed_key_introspects(): void
    {
        $this->artisan('identity:service-account:create', ['service_code' => 'sofrito-app', '--application' => 'sofrito', '--scope' => ['reviews:read']]);

        $plain = $this->lastPrintedKey();

        $this->withToken($plain)->getJson('/api/v1/api-key/introspect')
            ->assertOk()
            ->assertJsonPath('application', 'sofrito')
            ->assertJsonPath('owner.service_code', 'sofrito-app');
    }

    public function test_it_refuses_a_duplicate_service_code(): void
    {
        ServiceAccount::create(['name' => 'x', 'service_code' => 'dup', 'status' => 'ACTIVE']);

        $this->artisan('identity:service-account:create', ['service_code' => 'dup'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    }

    public function test_it_refuses_an_unknown_application(): void
    {
        $this->artisan('identity:service-account:create', ['service_code' => 'new', '--application' => 'nope'])
            ->expectsOutputToContain('Unknown application')
            ->assertFailed();
    }

    private function lastPrintedKey(): string
    {
        // The command stores the plain key on the container for test access only.
        return app('identity.last_created_key');
    }
}
```

- [ ] **Step 2: Run to confirm failure**

Run: `php artisan test --compact tests/Feature/Console/CreateServiceAccountCommandTest.php`
Expected: `Command "identity:service-account:create" is not defined`.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use App\Actions\ApiKeys\CreateApiKeyAction;
use App\Actions\ServiceAccounts\CreateServiceAccountAction;
use App\Models\Application;
use App\Models\ServiceAccount;
use Illuminate\Console\Command;

class CreateServiceAccountCommand extends Command
{
    protected $signature = 'identity:service-account:create
        {service_code : Unique code, e.g. tabibihub-app}
        {--name= : Human name (defaults to the code)}
        {--application= : Application code this account acts for}
        {--scope=* : Scopes granted to the account and its first key}
        {--tenant= : Optional tenant reference that pins every call}
        {--expires= : Optional key expiry (any date string)}';

    protected $description = 'Create a service account with its first API key and print the key once';

    public function handle(CreateServiceAccountAction $createAccount, CreateApiKeyAction $createKey): int
    {
        $code = (string) $this->argument('service_code');

        if (ServiceAccount::where('service_code', $code)->exists()) {
            $this->error("Service account [{$code}] already exists.");

            return self::FAILURE;
        }

        $application = $this->option('application');
        if ($application !== null && ! Application::where('code', $application)->exists()) {
            $this->error("Unknown application [{$application}]. Run identity:sync-applications or check config/identity.php.");

            return self::FAILURE;
        }

        $scopes = array_values(array_filter((array) $this->option('scope')));

        $account = $createAccount->handle([
            'name' => (string) ($this->option('name') ?: $code),
            'service_code' => $code,
            'application' => $application,
            'tenant_reference' => $this->option('tenant'),
            'scopes' => $scopes,
        ]);

        $result = $createKey->handle($account, [
            'name' => 'initial',
            'scopes' => $scopes,
            'expires_at' => $this->option('expires'),
        ]);

        $this->table(['Field', 'Value'], [
            ['service_code', $account->service_code],
            ['application', $application ?? '—'],
            ['tenant_reference', $account->tenant_reference ?? '—'],
            ['scopes', implode(', ', $scopes) ?: '—'],
            ['key_prefix', $result['api_key']->key_prefix],
        ]);
        $this->warn('Store this key now; it is not shown again:');
        $this->line($result['plain_text_key']);

        app()->instance('identity.last_created_key', $result['plain_text_key']);

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test and the suite**

Run: `php artisan test --compact tests/Feature/Console/CreateServiceAccountCommandTest.php && php artisan test --compact`
Expected: 4 pass; whole suite green.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Console/Commands/CreateServiceAccountCommand.php tests/Feature/Console/CreateServiceAccountCommandTest.php
git commit -m "feat(identity): identity:service-account:create command

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

---

### Task 10: Review-rating adopts the standard

**Files:**
- Modify: `/Users/enadabuzaid/Herd/review-rating-service/bootstrap/app.php`
- Modify: `/Users/enadabuzaid/Herd/review-rating-service/routes/api.php`
- Delete: `/Users/enadabuzaid/Herd/review-rating-service/app/Http/Middleware/VerifyServiceToken.php`
- Delete: `/Users/enadabuzaid/Herd/review-rating-service/fix_migrations.php`
- Modify: `/Users/enadabuzaid/Herd/review-rating-service/config/review-service.php`
- Modify: `/Users/enadabuzaid/Herd/review-rating-service/phpunit.xml`
- Modify: `/Users/enadabuzaid/Herd/review-rating-service/tests/TestCase.php`
- Create: `/Users/enadabuzaid/Herd/review-rating-service/app/Support/ModeratorReference.php`
- Modify: `/Users/enadabuzaid/Herd/review-rating-service/app/Http/Controllers/Api/V1/ReportController.php:51`
- Modify: `/Users/enadabuzaid/Herd/review-rating-service/app/Services/ModerationService.php:48`
- Rename: `tests/Feature/ServiceTokenAuthTest.php` → `tests/Feature/ServiceAuthTest.php`
- Create: `/Users/enadabuzaid/Herd/review-rating-service/docs/SERVICE.md`
- Modify: `/Users/enadabuzaid/Herd/review-rating-service/README.md` (auth section)
- Modify: `/Users/enadabuzaid/Herd/review-rating-service/.env.example` (replace `REVIEW_SERVICE_TOKEN` with `IDENTITY_SERVICE_URL`)

**Interfaces:**
- Consumes: package aliases `service.auth`, `service.scope`; `ServiceAuth::fake`, `ServiceAuth::TEST_TOKEN`; `Reference`.
- Produces: scopes `reviews:read` (all GET), `reviews:write` (programs/store, reviews store/update/destroy, reply, helpful, media, reports/store), `reviews:moderate` (approve/reject/flag/hide/restore/remove, review-reports index/dismiss/action). `ModeratorReference::fromRequest(Request): string`.

- [ ] **Step 1: Rewrite the auth test first**

Delete `tests/Feature/ServiceTokenAuthTest.php` and create `tests/Feature/ServiceAuthTest.php`:

```php
<?php

namespace Tests\Feature;

use Enadstack\ApiContracts\Testing\ServiceAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_without_a_key_is_rejected(): void
    {
        $this->withoutServiceKey()
            ->getJson('/api/v1/reviews?target_type=SHOP&target_reference=1')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_a_token_without_the_ia_live_prefix_is_rejected(): void
    {
        $this->withoutServiceKey()
            ->withToken('not-the-token')
            ->getJson('/api/v1/reviews?target_type=SHOP&target_reference=1')
            ->assertStatus(401);
    }

    public function test_a_revoked_key_is_rejected(): void
    {
        ServiceAuth::fake()->rejecting(ServiceAuth::TEST_TOKEN);

        $this->getJson('/api/v1/reviews?target_type=SHOP&target_reference=1')->assertStatus(401);
    }

    public function test_a_valid_key_with_the_read_scope_can_list_reviews(): void
    {
        ServiceAuth::fake(scopes: ['reviews:read']);

        $this->getJson('/api/v1/reviews?target_type=SHOP&target_reference=1')->assertStatus(200);
    }

    public function test_moderation_needs_the_moderate_scope(): void
    {
        ServiceAuth::fake(scopes: ['reviews:read', 'reviews:write']);

        $this->postJson('/api/v1/reviews/some-id/approve')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN')
            ->assertJsonPath('error.details.required_scope', 'reviews:moderate');
    }

    public function test_identity_being_down_is_a_503(): void
    {
        ServiceAuth::fake()->unavailable();

        $this->getJson('/api/v1/reviews?target_type=SHOP&target_reference=1')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'IDENTITY_UNAVAILABLE');
    }

    public function test_a_malformed_moderator_reference_is_422(): void
    {
        $this->withHeader('X-Moderator-Reference', 'bob')
            ->postJson('/api/v1/reviews/some-id/approve')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_REFERENCE');
    }

    public function test_the_health_check_stays_open(): void
    {
        $this->withoutServiceKey()->get('/up')->assertStatus(200);
    }
}
```

- [ ] **Step 2: Update `tests/TestCase.php`**

```php
<?php

namespace Tests;

use Enadstack\ApiContracts\Testing\ServiceAuth;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every API route is behind service.auth. Tests run as a fully-scoped
     * caller by default and opt out when testing the guard itself.
     */
    protected function setUp(): void
    {
        parent::setUp();

        ServiceAuth::fake(serviceCode: 'test-suite', application: 'sofrito', scopes: ['reviews:*']);
        $this->withToken(ServiceAuth::TEST_TOKEN);
    }

    protected function withoutServiceKey(): static
    {
        unset($this->defaultHeaders['Authorization']);

        return $this;
    }
}
```

- [ ] **Step 3: Run to confirm failure**

Run: `cd /Users/enadabuzaid/Herd/review-rating-service && php artisan test --compact tests/Feature/ServiceAuthTest.php`
Expected: failures (routes still expect `X-Service-Token`; scope route missing; 422 test fails).

- [ ] **Step 4: Swap the middleware in `bootstrap/app.php`**

Replace the `VerifyServiceToken` import and prepend with the package middleware:

```php
use Enadstack\ApiContracts\Http\Middleware\AuthenticateServiceKey;
```

```php
        $middleware->api(prepend: [
            AuthenticateServiceKey::class,
        ]);
```

Delete `app/Http/Middleware/VerifyServiceToken.php`.

- [ ] **Step 5: Scope the routes**

Replace the body of `routes/api.php` with:

```php
<?php

use App\Http\Controllers\Api\V1\AggregateController;
use App\Http\Controllers\Api\V1\EligibilityController;
use App\Http\Controllers\Api\V1\HelpfulVoteController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\ModerationController;
use App\Http\Controllers\Api\V1\ProgramController;
use App\Http\Controllers\Api\V1\ReplyController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReviewController;
use Illuminate\Support\Facades\Route;

// service.auth is prepended to the whole api group in bootstrap/app.php.
Route::prefix('v1')->group(function () {
    Route::middleware('service.scope:reviews:read')->group(function () {
        Route::get('/programs/{code}', [ProgramController::class, 'show']);
        Route::get('/review-eligibility', [EligibilityController::class, 'check']);
        Route::get('/reviews', [ReviewController::class, 'index']);
        Route::get('/reviews/{id}', [ReviewController::class, 'show']);
        Route::get('/aggregates', [AggregateController::class, 'index']);
    });

    Route::middleware('service.scope:reviews:write')->group(function () {
        Route::post('/programs', [ProgramController::class, 'store']);
        Route::post('/reviews', [ReviewController::class, 'store']);
        Route::patch('/reviews/{id}', [ReviewController::class, 'update']);
        Route::delete('/reviews/{id}', [ReviewController::class, 'destroy']);
        Route::post('/reviews/{id}/reply', [ReplyController::class, 'store']);
        Route::patch('/reviews/{id}/reply', [ReplyController::class, 'store']);
        Route::delete('/reviews/{id}/reply', [ReplyController::class, 'destroy']);
        Route::post('/reviews/{id}/reports', [ReportController::class, 'store']);
        Route::post('/reviews/{id}/helpful', [HelpfulVoteController::class, 'store']);
        Route::delete('/reviews/{id}/helpful', [HelpfulVoteController::class, 'destroy']);
        Route::post('/reviews/{id}/media', [MediaController::class, 'store']);
        Route::delete('/reviews/{id}/media/{media_id}', [MediaController::class, 'destroy']);
    });

    Route::middleware('service.scope:reviews:moderate')->group(function () {
        Route::post('/reviews/{id}/approve', [ModerationController::class, 'approve']);
        Route::post('/reviews/{id}/reject', [ModerationController::class, 'reject']);
        Route::post('/reviews/{id}/flag', [ModerationController::class, 'flag']);
        Route::post('/reviews/{id}/hide', [ModerationController::class, 'hide']);
        Route::post('/reviews/{id}/restore', [ModerationController::class, 'restore']);
        Route::post('/reviews/{id}/remove', [ModerationController::class, 'remove']);
        Route::get('/review-reports', [ReportController::class, 'index']);
        Route::post('/review-reports/{id}/dismiss', [ReportController::class, 'dismiss']);
        Route::post('/review-reports/{id}/action', [ReportController::class, 'action']);
    });
});
```

- [ ] **Step 6: Validate the moderator reference**

`app/Support/ModeratorReference.php`:

```php
<?php

namespace App\Support;

use Enadstack\ApiContracts\Support\Reference;
use Illuminate\Http\Request;

/**
 * Who performed a moderation action. Absent header = the system itself;
 * present header must be a well-formed reference (throws INVALID_REFERENCE).
 */
final class ModeratorReference
{
    public const HEADER = 'X-Moderator-Reference';

    public const SYSTEM = 'system';

    public static function fromRequest(Request $request): string
    {
        $raw = $request->header(self::HEADER);

        if ($raw === null || $raw === '') {
            return self::SYSTEM;
        }

        return (string) Reference::parse($raw);
    }
}
```

In `ReportController.php` line 51 replace `$resolver = $request->header('X-Moderator-Reference', 'system');` with `$resolver = \App\Support\ModeratorReference::fromRequest($request);`.

In `ModerationService.php` line 48 replace `$moderatorReference = request()->header('X-Moderator-Reference', 'system');` with `$moderatorReference = \App\Support\ModeratorReference::fromRequest(request());`.

`Reference::parse` throws `InvalidReferenceException`, which the renderer turns into 422 `INVALID_REFERENCE`. The moderation route must run the scope middleware before reaching the service, so the 422 test above sends the default full-scope token.

- [ ] **Step 7: Config, env, cleanup**

`config/review-service.php`: remove the `service_token` entry and its comment block. If the array becomes empty, keep the file with `return [];` and a one-line comment `// Service-specific settings. Auth is configured by config/api-contracts.php.`

`phpunit.xml`: delete the line `<env name="REVIEW_SERVICE_TOKEN" value="test-service-token"/>` and add `<env name="IDENTITY_SERVICE_URL" value="http://identity.test"/>`.

`.env.example`: replace `REVIEW_SERVICE_TOKEN=` with `IDENTITY_SERVICE_URL=http://identity-access-service.test`.

`git rm fix_migrations.php`.

Grep for leftovers: `grep -rn "X-Service-Token\|service_token\|REVIEW_SERVICE_TOKEN\|VerifyServiceToken" app config routes tests bootstrap README.md .env.example` must return nothing (README fixed in Step 9).

- [ ] **Step 8: Run the whole suite**

Run: `php artisan test --compact`
Expected: 48 tests (45 existing minus 5 old auth tests plus 8 new) pass. Any other test that fails with 401/403 is sending its own header set; make it use the default token (remove the explicit `X-Service-Token`), do not weaken it.

- [ ] **Step 9: Service doc and README**

`docs/SERVICE.md`:

```markdown
# review-rating-service

Standard: `enadstack/laravel-api-contracts` → `STANDARD.md`.

## Owns
`review_programs`, `reviews`, `review_replies`, `review_reports`,
`review_helpful_votes`, `review_media`, `review_aggregates`,
`outbox_messages`, `outbox_events` (legacy local outbox, to be replaced by
the package outbox in a later phase).

## References (not owned)
`tenant_reference`, `merchant_reference`, `target_reference`
(`{app}:shop:{id}` / `{app}:item:{id}`), `reviewer_reference`
(`identity:{public_id}` today; `{app}:user:{public_id}` going forward),
`X-Moderator-Reference` (a reference, or absent = system).

## Scopes
| Scope | Routes |
|---|---|
| `reviews:read` | programs/{code}, review-eligibility, reviews (list/show), aggregates |
| `reviews:write` | programs, reviews (create/update/delete), reply, reports/store, helpful, media |
| `reviews:moderate` | approve/reject/flag/hide/restore/remove, review-reports |

## Events emitted
Via local `OutboxService`: `review.created`, `review.published`,
`review.moderated` (see `app/Services/OutboxService.php`).

## Tests
`php artisan test --compact` (sqlite in-memory). Auth matrix in
`tests/Feature/ServiceAuthTest.php`.
```

README: replace the authentication paragraph that mentions `X-Service-Token` with:

```markdown
### Authentication

Every `/api/v1` route requires an identity-issued service key:
`Authorization: Bearer ia_live_…` with scopes `reviews:read`,
`reviews:write` and/or `reviews:moderate`. Create one in
identity-access-service:

```bash
php artisan identity:service-account:create sofrito-app --application=sofrito --scope=reviews:*
```

Set `IDENTITY_SERVICE_URL` in `.env`. See `docs/SERVICE.md`.
```

- [ ] **Step 10: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "feat(reviews): adopt platform service auth and scopes from laravel-api-contracts

Replaces the shared X-Service-Token secret with identity-introspected
ia_live_ keys, scopes routes as reviews:read/write/moderate, validates
X-Moderator-Reference as a Reference, and removes the migration rewrite
script.

Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01BYJN1TGofpkkASYW3ehMy8"
```

- [ ] **Step 11: Manual end-to-end check on Herd**

In identity-access-service (uses its real local DB):

```bash
cd /Users/enadabuzaid/Herd/identity-access-service && php artisan identity:sync-applications && php artisan identity:service-account:create sofrito-app --application=sofrito --scope='reviews:*'
```

Copy the printed key, then:

```bash
curl -s -H "Authorization: Bearer <KEY>" "http://review-rating-service.test/api/v1/reviews?target_type=SHOP&target_reference=1" | head -c 300
curl -s -o /dev/null -w "%{http_code}\n" "http://review-rating-service.test/api/v1/reviews?target_type=SHOP&target_reference=1"
```

Expected: first prints a JSON body (200); second prints `401`. Record both results in the task report. Sofrito's `REVIEW_SERVICE_TOKEN` env is now obsolete; Sofrito is updated in plan 3 (SDK migration), so until then Sofrito's review features against the local review service will 401. That is expected and noted in the roadmap.

---

## Self-review against the spec

- §4.1 units: Reference (T1), ServiceCaller/Introspector/HttpIntrospector (T2), AuthenticateServiceKey/RequireScope (T3), ResolveTenantContext/HasTenantReference (T4), Idempotency (T5), OutboxEvent/RecordOutboxEvent/Publisher/HttpPublisher/ProcessOutboxCommand (T6), ServiceAuth fake (T2), ServiceUnavailableException → realised as `IdentityUnavailableException` (T2), config blocks (T2, T5, T6), `STANDARD.md` (T7). Gap: none.
- §4.3 identity: applications (T8), `application_id` + introspect fields (T8), command (T9), roles routes and fix scripts (plan 1).
- §4.5 review-rating: middleware swap, scopes, moderator reference, fix script (T10). Analytics: no task (spec says no change this phase; the `Reference` rule bump is deferred to when analytics is next touched).
- §5 error table: 401/403/503/422 covered by T3/T4 tests; idempotency replay header T5; outbox backoff T6.
- §6 testing: package tests per unit; identity tests; review-rating `ServiceAuthTest`.
- Type consistency: `ServiceCaller::fromRequest(Request)` used in T3, T4, T5; `ServiceAuth::fake(serviceCode, application, scopes, tenantReference)` and `ServiceAuth::TEST_TOKEN` used in T3–T5, T10; `Reference::parse/tryParse/is/belongsTo` used in T4, T10; `RecordOutboxEvent::__invoke(type, aggregateType, aggregateId, payload, version)` matches T6 test calls.
- Placeholders: none. Every code step is complete.
