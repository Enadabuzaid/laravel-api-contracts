<?php

namespace Enadstack\ApiContracts\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Event schema from SERVICE_STANDARD §6. Additive payload changes keep
 * `version`; a breaking change bumps it.
 *
 * @implements Arrayable<string, mixed>
 */
final class EventEnvelope implements \JsonSerializable, Arrayable
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly int $version,
        public readonly string $producer,
        public readonly string $application,
        public readonly string $tenantReference,
        public readonly CarbonImmutable $occurredAt,
        public readonly array $data = [],
    ) {
        if (! preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+){2,}$/', $type)) {
            throw new InvalidArgumentException("Event type [{$type}] must look like {domain}.{entity}.{past_tense}.");
        }
        if ($version < 1) {
            throw new InvalidArgumentException('Event version must be >= 1.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(string $type, string $producer, string $application, string $tenantReference, array $data = [], int $version = 1): self
    {
        return new self((string) Str::ulid(), $type, $version, $producer, $application, $tenantReference, CarbonImmutable::now('UTC'), $data);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        foreach (['id', 'type', 'version', 'producer', 'application', 'tenant_reference', 'occurred_at', 'data'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("Event envelope is missing [{$field}].");
            }
        }

        return new self(
            $payload['id'], $payload['type'], (int) $payload['version'], $payload['producer'],
            $payload['application'], $payload['tenant_reference'],
            CarbonImmutable::parse($payload['occurred_at'])->utc(), (array) $payload['data'],
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'version' => $this->version,
            'producer' => $this->producer,
            'application' => $this->application,
            'tenant_reference' => $this->tenantReference,
            'occurred_at' => $this->occurredAt->utc()->format('Y-m-d\TH:i:s\Z'),
            'data' => (object) $this->data,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
