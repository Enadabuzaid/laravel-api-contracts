<?php

namespace Enadstack\ApiContracts\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Immutable result of an identity-access-service API key introspection.
 */
final readonly class ServicePrincipal
{
    public const ATTRIBUTE = 'service.principal';

    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $owner
     */
    public function __construct(
        public string $application,
        public array $scopes,
        public array $owner = [],
        public ?string $tenantReference = null,
        public ?int $apiKeyId = null,
        public ?int $expiresAt = null,
    ) {}

    public static function from(Request $request): ?self
    {
        $p = $request->attributes->get(self::ATTRIBUTE);

        return $p instanceof self ? $p : null;
    }

    /**
     * Build from an introspection payload; null when it is not a usable
     * active response.
     *
     * @param  array<string, mixed>  $d
     */
    public static function fromIntrospection(array $d): ?self
    {
        if (! isset($d['active']) || $d['active'] !== true) {
            return null;
        }
        if (! isset($d['application']) || ! is_string($d['application']) || $d['application'] === '') {
            return null;
        }
        if (! isset($d['scopes']) || ! is_array($d['scopes']) || array_filter($d['scopes'], fn ($s) => ! is_string($s))) {
            return null;
        }
        $tenant = $d['tenant_reference'] ?? null;
        if ($tenant !== null && ! is_string($tenant)) {
            return null;
        }
        $expires = null;
        if (! empty($d['expires_at'])) {
            try {
                $expires = Carbon::parse($d['expires_at'])->getTimestamp();
            } catch (\Throwable) {
                return null;
            }
        }

        return new self(
            $d['application'],
            array_values($d['scopes']),
            is_array($d['owner'] ?? null) ? $d['owner'] : [],
            $tenant === '' ? null : $tenant,
            isset($d['api_key_id']) && is_numeric($d['api_key_id']) ? (int) $d['api_key_id'] : null,
            $expires,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'active' => true,
            'application' => $this->application,
            'scopes' => $this->scopes,
            'owner' => $this->owner,
            'tenant_reference' => $this->tenantReference,
            'api_key_id' => $this->apiKeyId,
            'expires_at' => $this->expiresAt === null ? null : Carbon::createFromTimestamp($this->expiresAt)->toIso8601String(),
        ];
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= Carbon::now()->getTimestamp();
    }

    public function hasScope(string $scope): bool
    {
        foreach ($this->scopes as $granted) {
            if ($granted === '*' || $granted === $scope) {
                return true;
            }
            if (str_ends_with($granted, ':*') && str_starts_with($scope, substr($granted, 0, -1))) {
                return true;
            }
        }

        return false;
    }
}
