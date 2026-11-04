<?php

namespace Enadstack\ApiContracts\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Validates an API key against identity-access-service. Results are cached
 * per SHA-256 of the key; the raw key is never cached, logged or stored.
 */
class ApiKeyIntrospector
{
    /**
     * @throws IntrospectionUnavailableException identity unreachable / 5xx / unusable body
     */
    public function introspect(string $key): ?ServicePrincipal
    {
        $hash = hash('sha256', $key);
        $cacheKey = 'api-contracts:introspect:'.$hash;
        $cache = Cache::store(config('api-contracts.identity.cache_store'));

        $cached = $cache->get($cacheKey);
        if (is_array($cached) && ($p = ServicePrincipal::fromIntrospection($cached))) {
            return $p;
        }

        $principal = $this->fetch($key);
        if ($principal === null) {
            return null;
        }

        $ttl = max(0, (int) config('api-contracts.identity.cache_ttl', 60));
        if ($principal->expiresAt !== null) {
            $ttl = min($ttl, $principal->expiresAt - Carbon::now()->getTimestamp());
        }
        if ($ttl > 0) {
            $cache->put($cacheKey, $principal->toArray(), $ttl);
        }

        return $principal;
    }

    private function fetch(string $key): ?ServicePrincipal
    {
        $base = rtrim((string) config('api-contracts.identity.base_url'), '/');
        if ($base === '') {
            Log::error('service.auth: identity.base_url is not configured');
            throw new IntrospectionUnavailableException('identity base_url missing');
        }

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout((int) config('api-contracts.identity.timeout', 3))
                ->get($base.config('api-contracts.identity.path', '/api/v1/api-key/introspect'));
        } catch (ConnectionException) {
            Log::warning('service.auth: identity unreachable');
            throw new IntrospectionUnavailableException('identity unreachable');
        }

        if (in_array($response->status(), [401, 403, 404], true)) {
            return null;
        }
        if (! $response->successful()) {
            Log::warning('service.auth: identity returned unexpected status', ['status' => $response->status()]);
            throw new IntrospectionUnavailableException('identity status '.$response->status());
        }

        $body = $response->json();
        if (! is_array($body) || ! array_key_exists('active', $body)) {
            Log::warning('service.auth: identity returned an unusable body');
            throw new IntrospectionUnavailableException('unusable body');
        }
        if ($body['active'] !== true) {
            return null;
        }

        $principal = ServicePrincipal::fromIntrospection($body);
        if ($principal === null) {
            Log::warning('service.auth: identity returned an unusable body');
            throw new IntrospectionUnavailableException('unusable body');
        }

        return $principal;
    }
}
