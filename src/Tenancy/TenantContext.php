<?php

namespace Enadstack\ApiContracts\Tenancy;

/**
 * The tenant (and application) the current request/job acts for. Bound as a
 * scoped container instance, so it is reset per request / queued job.
 */
class TenantContext
{
    private ?string $reference = null;

    private ?string $application = null;

    public function set(string $reference, string $application): void
    {
        $this->reference = $reference;
        $this->application = $application;
    }

    public function clear(): void
    {
        $this->reference = null;
        $this->application = null;
    }

    public function isSet(): bool
    {
        return $this->reference !== null && $this->application !== null;
    }

    public function reference(): ?string
    {
        return $this->reference;
    }

    public function application(): ?string
    {
        return $this->application;
    }

    /**
     * Run $callback inside a tenant context (queue jobs, console, tests) and
     * restore the previous context afterwards, even on exceptions.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function within(string $reference, string $application, callable $callback): mixed
    {
        $ctx = app(self::class);
        [$prevRef, $prevApp] = [$ctx->reference, $ctx->application];
        $ctx->set($reference, $application);

        try {
            return $callback();
        } finally {
            $ctx->reference = $prevRef;
            $ctx->application = $prevApp;
        }
    }
}
