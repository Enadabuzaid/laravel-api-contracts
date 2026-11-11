<?php

namespace Enadstack\ApiContracts\Http\Middleware;

use Closure;
use Enadstack\ApiContracts\Auth\ServicePrincipal;
use Enadstack\ApiContracts\Http\Responses\ApiResponses;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * service.scope:a:b,c:d — requires ALL listed scopes (after service.auth).
 */
class ServiceScope
{
    use ApiResponses;

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $principal = ServicePrincipal::from($request);
        if ($principal === null) {
            return $this->errorResponse('UNAUTHENTICATED', 'Authentication required.', [], 401);
        }

        $missing = array_values(array_filter($scopes, fn ($s) => ! $principal->hasScope($s)));
        if ($missing !== []) {
            return $this->errorResponse('INSUFFICIENT_SCOPE', 'The API key lacks the required scope.', ['missing' => $missing], 403);
        }

        return $next($request);
    }
}
