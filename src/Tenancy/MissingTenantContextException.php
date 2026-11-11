<?php

namespace Enadstack\ApiContracts\Tenancy;

use Enadstack\ApiContracts\Contracts\HasErrorCode;
use RuntimeException;

/**
 * Thrown when tenant-scoped data is touched without (or against) a tenant
 * context. Renders as a 500-class programming error: it must never be
 * silently turned into "all rows".
 */
class MissingTenantContextException extends RuntimeException implements HasErrorCode
{
    public function errorCode(): string
    {
        return 'TENANT_CONTEXT_REQUIRED';
    }

    public function errorStatus(): int
    {
        return 500;
    }

    public function errorDetails(): array
    {
        return [];
    }
}
