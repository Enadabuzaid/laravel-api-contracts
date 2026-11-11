<?php

namespace Enadstack\ApiContracts\Http\Middleware;

use Closure;
use Enadstack\ApiContracts\Http\Responses\ApiResponses;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores the response of a write per (caller, Idempotency-Key) and replays it.
 * Requires the `idempotency_keys` table (publish tag `api-contracts-migrations`).
 */
class Idempotency
{
    use ApiResponses;

    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if ($key === null || $key === '' || $request->isMethodSafe()) {
            return $next($request);
        }

        $caller = $this->caller($request);
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
        $table = DB::table('idempotency_keys');

        $table->where('caller', $caller)->where('idempotency_key', $key)
            ->where('expires_at', '<=', now())->delete();

        try {
            $table->insert([
                'caller' => $caller,
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'expires_at' => now()->addSeconds((int) config('api-contracts.idempotency.ttl', 86400)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->existing($caller, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->release($caller, $key);

            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->release($caller, $key);

            return $response;
        }

        DB::table('idempotency_keys')->where('caller', $caller)->where('idempotency_key', $key)->update([
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
            'updated_at' => now(),
        ]);

        return $response;
    }

    protected function existing(string $caller, string $key, string $hash): Response
    {
        $row = DB::table('idempotency_keys')->where('caller', $caller)->where('idempotency_key', $key)->first();

        if ($row === null) {
            return $this->errorResponse('IDEMPOTENCY_KEY_IN_PROGRESS', 'A request with this Idempotency-Key is being processed.', [], 409);
        }

        if (! hash_equals($row->request_hash, $hash)) {
            return $this->errorResponse('IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was already used with a different request body.', [], 422);
        }

        if ($row->response_status === null) {
            return $this->errorResponse('IDEMPOTENCY_KEY_IN_PROGRESS', 'A request with this Idempotency-Key is being processed.', [], 409);
        }

        $response = new Response((string) $row->response_body, (int) $row->response_status, ['Content-Type' => 'application/json']);
        $response->headers->set(self::REPLAYED_HEADER, 'true');

        return $response;
    }

    protected function release(string $caller, string $key): void
    {
        DB::table('idempotency_keys')->where('caller', $caller)->where('idempotency_key', $key)->delete();
    }

    /**
     * The authenticated service account/user if the request carries one,
     * otherwise a hash of the bearer token, otherwise the client IP.
     */
    protected function caller(Request $request): string
    {
        $caller = $request->attributes->get('service_account')
            ?? $request->user()?->getAuthIdentifier()
            ?? ($request->bearerToken() ? hash('sha256', $request->bearerToken()) : $request->ip());

        return substr((string) (is_scalar($caller) ? $caller : json_encode($caller)), 0, 128);
    }
}
