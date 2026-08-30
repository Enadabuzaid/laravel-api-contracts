<?php

namespace Enadstack\ApiContracts\Tests;

use Enadstack\ApiContracts\ApiContractsServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ApiContractsServiceProvider::class];
    }
}
