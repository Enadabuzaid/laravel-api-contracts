<?php

namespace Enadstack\ApiContracts\Http\Middleware;

use Closure;
use Enadstack\ApiContracts\Auth\ApiKeyIntrospector;
use Enadstack\ApiContracts\Auth\IntrospectionUnavailableException;
use Enadstack\ApiContracts\Auth\ServicePrincipal;
use Enadstack\ApiContracts\Http\Responses\ApiResponses;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * service.auth — validates the caller's identity-issued API key and that it
 * belongs to the application named in X-Application. Fails closed.
 */
class ServiceAuth
{
    use ApiResponses;

    public function __construct(private ApiKeyIntrospector $introspector) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->bearerToken();
        if ($key === null || $key === '') {
            return $this->unauthenticated();
        }

        try {
            $principal = $this->introspector->introspect($key);
        } catch (IntrospectionUnavailableException) {
            return $this->errorResponse('SERVICE_AUTH_UNAVAILABLE', 'Authentication service is unavailable.', [], 503);
        }

        if ($principal === null || $principal->isExpired()) {
            return $this->unauthenticated();
        }

        $application = $request->header('X-Application');
        if (! is_string($application) || $application === '' || ! hash_equals($principal->application, $application)) {
            return $this->errorResponse('APPLICATION_MISMATCH', 'The API key does not belong to the requested application.', [], 403);
        }

        $request->attributes->set(ServicePrincipal::ATTRIBUTE, $principal);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return $this->errorResponse('UNAUTHENTICATED', 'Authentication required.', [], 401);
    }
}
