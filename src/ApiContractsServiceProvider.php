<?php

namespace Enadstack\ApiContracts;

use Illuminate\Support\ServiceProvider;

class ApiContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-contracts.php', 'api-contracts');

        $this->app->scoped(Tenancy\TenantContext::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/api-contracts.php' => config_path('api-contracts.php'),
            ], 'api-contracts-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_idempotency_keys_table.php.stub' => database_path('migrations/'.date('Y_m_d_His').'_create_idempotency_keys_table.php'),
                __DIR__.'/../database/migrations/create_outbox_events_table.php.stub' => database_path('migrations/'.date('Y_m_d_His', time() + 1).'_create_outbox_events_table.php'),
            ], 'api-contracts-migrations');

            $this->commands([Console\ProcessOutboxCommand::class]);
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('idempotency', Http\Middleware\Idempotency::class);
        $router->aliasMiddleware('service.auth', Http\Middleware\ServiceAuth::class);
        $router->aliasMiddleware('service.scope', Http\Middleware\ServiceScope::class);
        $router->aliasMiddleware('tenant.context', Http\Middleware\EnsureTenantContext::class);
    }
}
