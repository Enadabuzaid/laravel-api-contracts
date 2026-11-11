<?php

namespace Enadstack\ApiContracts\Tests\Feature;

use Enadstack\ApiContracts\Auth\ServicePrincipal;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

class ServiceAuthTest extends TestCase
{
    private const KEY = 'test-key-not-a-real-secret';

    private const URL = 'http://identity.test/api/v1/api-key/introspect';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('api-contracts.identity.base_url', 'http://identity.test');
        $app['config']->set('api-contracts.identity.cache_ttl', 60);
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('service.auth')->get('/ping', function (Request $r) {
            $p = ServicePrincipal::from($r);

            return response()->json([
                'application' => $p->application,
                'scopes' => $p->scopes,
                'tenant' => $p->tenantReference,
                'owner' => $p->owner,
            ]);
        });
        Route::middleware(['service.auth', 'service.scope:loyalty:earn,loyalty:read'])->get('/scoped', fn () => response()->json(['ok' => true]));
    }

    private function active(array $override = []): array
    {
        return array_merge([
            'active' => true,
            'api_key_id' => 5,
            'scopes' => ['loyalty:earn', 'loyalty:read'],
            'expires_at' => null,
            'application' => 'sofrito',
            'tenant_reference' => null,
            'owner' => ['type' => 'SERVICE_ACCOUNT', 'id' => 3, 'service_code' => 'orders'],
        ], $override);
    }

    private function hit(string $uri = '/ping', array $headers = [])
    {
        return $this->getJson($uri, array_merge([
            'Authorization' => 'Bearer '.self::KEY,
            'X-Application' => 'sofrito',
        ], $headers));
    }

    public function test_success_exposes_principal_and_sends_bearer_to_identity(): void
    {
        Http::fake([self::URL => Http::response($this->active())]);

        $this->hit()->assertOk()
            ->assertJsonPath('application', 'sofrito')
            ->assertJsonPath('scopes.0', 'loyalty:earn')
            ->assertJsonPath('owner.service_code', 'orders');

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer '.self::KEY) && $r->url() === self::URL);
    }

    public function test_second_call_is_served_from_cache(): void
    {
        Http::fake([self::URL => Http::response($this->active())]);

        $this->hit()->assertOk();
        $this->hit()->assertOk();

        Http::assertSentCount(1);
    }

    public function test_cache_expires_after_ttl(): void
    {
        Http::fake([self::URL => Http::response($this->active())]);

        $this->hit()->assertOk();
        $this->travel(61)->seconds();
        $this->hit()->assertOk();

        Http::assertSentCount(2);
    }

    public function test_revocation_is_seen_after_ttl(): void
    {
        Http::fakeSequence(self::URL)->push($this->active())->push(['message' => 'no'], 401);

        $this->hit()->assertOk();
        $this->travel(61)->seconds();
        $this->hit()->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_missing_bearer_is_401_without_calling_identity(): void
    {
        Http::fake();

        $this->getJson('/ping', ['X-Application' => 'sofrito'])
            ->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');

        Http::assertNothingSent();
    }

    public function test_unknown_key_401(): void
    {
        Http::fake([self::URL => Http::response(['error' => ['code' => 'UNAUTHENTICATED']], 401)]);

        $this->hit()->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
    }

    public function test_inactive_key_401(): void
    {
        Http::fake([self::URL => Http::response($this->active(['active' => false]))]);

        $this->hit()->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_expired_key_401_even_if_identity_says_active(): void
    {
        Http::fake([self::URL => Http::response($this->active(['expires_at' => now()->subMinute()->toISOString()]))]);

        $this->hit()->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_cached_key_that_expires_meanwhile_is_rejected(): void
    {
        Http::fake([self::URL => Http::response($this->active(['expires_at' => now()->addSeconds(30)->toISOString()]))]);

        $this->hit()->assertOk();
        $this->travel(31)->seconds();
        $this->hit()->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_application_mismatch_403(): void
    {
        Http::fake([self::URL => Http::response($this->active())]);

        $this->hit('/ping', ['X-Application' => 'nadihub'])
            ->assertStatus(403)->assertJsonPath('error.code', 'APPLICATION_MISMATCH');
    }

    public function test_missing_application_header_403(): void
    {
        Http::fake([self::URL => Http::response($this->active())]);

        $this->getJson('/ping', ['Authorization' => 'Bearer '.self::KEY])
            ->assertStatus(403)->assertJsonPath('error.code', 'APPLICATION_MISMATCH');
    }

    public function test_mismatch_is_enforced_on_cache_hits_too(): void
    {
        Http::fake([self::URL => Http::response($this->active())]);

        $this->hit()->assertOk();
        $this->hit('/ping', ['X-Application' => 'nadihub'])->assertStatus(403);
    }

    public function test_fail_closed_on_server_error(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);

        $this->hit()->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_AUTH_UNAVAILABLE');
    }

    public function test_fail_closed_on_timeout(): void
    {
        Http::fake([self::URL => fn () => throw new ConnectionException('timeout')]);

        $this->hit()->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_AUTH_UNAVAILABLE');
    }

    public function test_fail_closed_on_garbage_body(): void
    {
        Http::fake([self::URL => Http::response('<html>nope</html>', 200)]);
        $this->hit()->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_AUTH_UNAVAILABLE');

        Http::fake([self::URL => Http::response(['active' => true, 'scopes' => 'x'], 200)]);
        $this->hit()->assertStatus(503);
    }

    public function test_failures_are_not_cached(): void
    {
        Http::fakeSequence(self::URL)->push('boom', 500)->push($this->active());

        $this->hit()->assertStatus(503);
        $this->hit()->assertOk();
    }

    public function test_unconfigured_identity_url_fails_closed(): void
    {
        config(['api-contracts.identity.base_url' => null]);
        Http::fake();

        $this->hit()->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_AUTH_UNAVAILABLE');
        Http::assertNothingSent();
    }

    public function test_scope_all_present_passes(): void
    {
        Http::fake([self::URL => Http::response($this->active())]);

        $this->hit('/scoped')->assertOk();
    }

    public function test_scope_missing_one_of_many_403_with_details(): void
    {
        Http::fake([self::URL => Http::response($this->active(['scopes' => ['loyalty:earn']]))]);

        $this->hit('/scoped')->assertStatus(403)
            ->assertJsonPath('error.code', 'INSUFFICIENT_SCOPE')
            ->assertJsonPath('error.details.missing', ['loyalty:read']);
    }

    public function test_wildcard_scope(): void
    {
        Http::fake([self::URL => Http::response($this->active(['scopes' => ['*']]))]);
        $this->hit('/scoped')->assertOk();
    }

    public function test_namespace_wildcard_scope(): void
    {
        Http::fake([self::URL => Http::response($this->active(['scopes' => ['loyalty:*']]))]);
        $this->hit('/scoped')->assertOk();
    }

    public function test_scope_without_service_auth_is_denied(): void
    {
        Route::middleware('service.scope:a:b')->get('/orphan', fn () => 'x');

        $this->getJson('/orphan')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_raw_key_never_in_cache_or_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function ($e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        Http::fakeSequence(self::URL)->push($this->active())->push('boom', 500);

        $this->hit()->assertOk();

        $ref = new \ReflectionProperty(Cache::store('array')->getStore(), 'storage');
        $storage = $ref->getValue(Cache::store('array')->getStore());
        $this->assertNotEmpty($storage);
        foreach ($storage as $k => $v) {
            $this->assertStringNotContainsString(self::KEY, (string) $k);
            $this->assertStringNotContainsString(self::KEY, serialize($v));
        }
        $this->assertStringContainsString(hash('sha256', self::KEY), implode(' ', array_keys($storage)));

        $this->travel(61)->seconds();
        $this->hit()->assertStatus(503);
        $this->assertStringNotContainsString(self::KEY, implode(' ', $logged));
    }
}
