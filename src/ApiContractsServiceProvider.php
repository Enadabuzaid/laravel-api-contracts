<?php

namespace Enadstack\ApiContracts;

use Illuminate\Support\ServiceProvider;

class ApiContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api-contracts.php', 'api-contracts');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/api-contracts.php' => config_path('api-contracts.php'),
            ], 'api-contracts-config');
        }
    }
}
