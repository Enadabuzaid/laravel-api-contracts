<?php

namespace Enadstack\ApiContracts\Tests\Feature;

use Enadstack\ApiContracts\Tenancy\HasTenantReference;
use Enadstack\ApiContracts\Tenancy\MissingTenantContextException;
use Enadstack\ApiContracts\Tenancy\TenantContext;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class TenantNote extends Model
{
    use HasTenantReference;

    protected $table = 'tenant_notes';

    protected $guarded = [];
}

class TenantContextTest extends TestCase
{
    private const URL = 'http://identity.test/api/v1/api-key/introspect';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('api-contracts.identity.base_url', 'http://identity.test');
        $app['config']->set('database.default', 'testing');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('tenant_notes', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_reference');
            $t->string('application');
            $t->string('body')->nullable();
            $t->timestamps();
        });

        Route::middleware(['service.auth', 'tenant.context'])->post('/notes', function () {
            $note = TenantNote::create(['body' => 'x']);

            return response()->json(['tenant' => $note->tenant_reference, 'app' => $note->application, 'count' => TenantNote::count()]);
        });
        Route::middleware(['service.auth', 'tenant.context'])->get('/ctx', function (Request $r) {
            return response()->json(['ref' => app(TenantContext::class)->reference(), 'app' => app(TenantContext::class)->application()]);
        });
    }

    private function identity(array $override = []): void
    {
        Http::fake([self::URL => Http::response(array_merge([
            'active' => true, 'scopes' => ['*'], 'expires_at' => null,
            'application' => 'sofrito', 'tenant_reference' => null,
            'owner' => ['type' => 'SERVICE_ACCOUNT', 'id' => 1],
        ], $override))]);
    }

    private function req(string $uri = '/ctx', array $h = [], string $m = 'getJson')
    {
        $headers = (array_merge(['Authorization' => 'Bearer k-test', 'X-Application' => 'sofrito'], $h));

        return $m === 'getJson' ? $this->getJson($uri, $headers) : $this->postJson($uri, [], $headers);
    }

    public function test_valid_tenant_sets_context(): void
    {
        $this->identity();

        $this->req('/ctx', ['X-Tenant-Reference' => 'sofrito:tenant:12'])
            ->assertOk()->assertJsonPath('ref', 'sofrito:tenant:12')->assertJsonPath('app', 'sofrito');
    }

    public function test_missing_header_400(): void
    {
        $this->identity();

        $this->req()->assertStatus(400)->assertJsonPath('error.code', 'TENANT_CONTEXT_REQUIRED');
    }

    #[DataProvider('malformed')]
    public function test_malformed_header_400(string $value): void
    {
        $this->identity();

        $this->req('/ctx', ['X-Tenant-Reference' => $value])
            ->assertStatus(400)->assertJsonPath('error.code', 'TENANT_CONTEXT_INVALID');
    }

    public static function malformed(): array
    {
        return [['sofrito:12'], ['sofrito:tenant:'], [':tenant:1'], ['sofrito:tenant:1:2'], ['sofrito:tenant:a b'], ['SOFRITO:tenant:1;drop']];
    }

    public function test_prefix_must_equal_application(): void
    {
        $this->identity();

        $this->req('/ctx', ['X-Tenant-Reference' => 'nadihub:tenant:12'])
            ->assertStatus(403)->assertJsonPath('error.code', 'TENANT_APPLICATION_MISMATCH');
    }

    public function test_key_pinned_to_other_tenant_403(): void
    {
        $this->identity(['tenant_reference' => 'sofrito:tenant:1']);

        $this->req('/ctx', ['X-Tenant-Reference' => 'sofrito:tenant:2'])
            ->assertStatus(403)->assertJsonPath('error.code', 'TENANT_FORBIDDEN');
        $this->req('/ctx', ['X-Tenant-Reference' => 'sofrito:tenant:1'])->assertOk();
    }

    public function test_pinned_key_may_omit_header(): void
    {
        $this->identity(['tenant_reference' => 'sofrito:tenant:1']);

        $this->req()->assertOk()->assertJsonPath('ref', 'sofrito:tenant:1');
    }

    public function test_pattern_is_configurable(): void
    {
        config(['api-contracts.tenant.pattern' => '/^(?<prefix>[a-z]+)-t-(?<id>\d+)$/']);
        $this->identity();

        $this->req('/ctx', ['X-Tenant-Reference' => 'sofrito-t-9'])->assertOk()->assertJsonPath('ref', 'sofrito-t-9');
        $this->req('/ctx', ['X-Tenant-Reference' => 'sofrito:tenant:9'])->assertStatus(400);
    }

    public function test_context_does_not_leak_between_requests(): void
    {
        $this->identity();
        $this->req('/ctx', ['X-Tenant-Reference' => 'sofrito:tenant:1'])->assertOk();

        $this->assertFalse(app(TenantContext::class)->isSet());
    }

    public function test_model_fails_closed_without_context(): void
    {
        $this->expectException(MissingTenantContextException::class);
        TenantNote::query()->get();
    }

    public function test_create_fails_closed_without_context(): void
    {
        $this->expectException(MissingTenantContextException::class);
        TenantNote::create(['body' => 'x']);
    }

    public function test_create_autofills_through_middleware(): void
    {
        $this->identity();

        $this->req('/notes', ['X-Tenant-Reference' => 'sofrito:tenant:7'], 'postJson')
            ->assertOk()->assertJsonPath('tenant', 'sofrito:tenant:7')->assertJsonPath('app', 'sofrito')->assertJsonPath('count', 1);
    }

    public function test_isolation_between_tenants(): void
    {
        TenantContext::within('sofrito:tenant:1', 'sofrito', fn () => TenantNote::create(['body' => 'a']));
        TenantContext::within('sofrito:tenant:2', 'sofrito', fn () => TenantNote::create(['body' => 'b']));

        $this->assertSame(['a'], TenantContext::within('sofrito:tenant:1', 'sofrito', fn () => TenantNote::pluck('body')->all()));
        $this->assertSame(['b'], TenantContext::within('sofrito:tenant:2', 'sofrito', fn () => TenantNote::pluck('body')->all()));
    }

    public function test_isolation_between_applications_with_same_tenant_id(): void
    {
        TenantContext::within('sofrito:tenant:1', 'sofrito', fn () => TenantNote::create(['body' => 'taf']));
        TenantContext::within('nadihub:tenant:1', 'nadihub', fn () => TenantNote::create(['body' => 'sof']));

        $this->assertSame(['taf'], TenantContext::within('sofrito:tenant:1', 'sofrito', fn () => TenantNote::pluck('body')->all()));
        $this->assertSame(['sof'], TenantContext::within('nadihub:tenant:1', 'nadihub', fn () => TenantNote::pluck('body')->all()));
    }

    public function test_cannot_create_row_for_another_tenant_inside_context(): void
    {
        $this->expectException(MissingTenantContextException::class);
        TenantContext::within('sofrito:tenant:1', 'sofrito', fn () => TenantNote::create([
            'body' => 'x', 'tenant_reference' => 'sofrito:tenant:2', 'application' => 'sofrito',
        ]));
    }

    public function test_within_restores_previous_context_even_on_exception(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->set('sofrito:tenant:1', 'sofrito');

        try {
            TenantContext::within('sofrito:tenant:2', 'sofrito', fn () => throw new \RuntimeException('x'));
        } catch (\RuntimeException) {
        }

        $this->assertSame('sofrito:tenant:1', $ctx->reference());
        $ctx->clear();
        $this->assertFalse($ctx->isSet());
    }
}
