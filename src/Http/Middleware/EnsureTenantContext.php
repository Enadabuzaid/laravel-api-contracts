<?php

namespace Enadstack\ApiContracts\Http\Middleware;

use Closure;
use Enadstack\ApiContracts\Auth\ServicePrincipal;
use Enadstack\ApiContracts\Http\Responses\ApiResponses;
use Enadstack\ApiContracts\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * tenant.context — requires a well-formed X-Tenant-Reference whose prefix is
 * the caller's application, honours key-level tenant pinning, and sets the
 * TenantContext for the request. Run after service.auth.
 */
class EnsureTenantContext
{
    use ApiResponses;

    public function handle(Request $request, Closure $next): Response
    {
        $principal = ServicePrincipal::from($request);
        $application = $principal?->application ?? $request->header('X-Application');
        if (! is_string($application) || $application === '') {
            return $this->errorResponse('UNAUTHENTICATED', 'Authentication required.', [], 401);
        }

        $pinned = $principal?->tenantReference;
        $reference = $request->header(config('api-contracts.tenant.header', 'X-Tenant-Reference'));
        if (! is_string($reference) || $reference === '') {
            if ($pinned === null) {
                return $this->errorResponse('TENANT_CONTEXT_REQUIRED', 'X-Tenant-Reference header is required.', [], 400);
            }
            $reference = $pinned;
        }

        if (! preg_match((string) config('api-contracts.tenant.pattern'), $reference, $m) || ! isset($m['prefix'])) {
            return $this->errorResponse('TENANT_CONTEXT_INVALID', 'X-Tenant-Reference is malformed.', [], 400);
        }

        if (! hash_equals($application, $m['prefix'])) {
            return $this->errorResponse('TENANT_APPLICATION_MISMATCH', 'Tenant reference does not belong to the requesting application.', [], 403);
        }

        if ($pinned !== null && ! hash_equals($pinned, $reference)) {
            return $this->errorResponse('TENANT_FORBIDDEN', 'The API key is not allowed to act for this tenant.', [], 403);
        }

        $context = app(TenantContext::class);
        $context->set($reference, $application);

        try {
            return $next($request);
        } finally {
            $context->clear();
        }
    }
}
