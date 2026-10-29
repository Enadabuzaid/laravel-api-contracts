<?php

namespace Enadstack\ApiContracts\Tests\Unit;

use Enadstack\ApiContracts\Events\EventEnvelope;
use Enadstack\ApiContracts\Tests\TestCase;
use InvalidArgumentException;

class EventEnvelopeTest extends TestCase
{
    public function test_serialises_to_the_standard_shape(): void
    {
        $e = EventEnvelope::make('loyalty.points.earned', 'loyalty-service', 'tafadel', 'tafadel:tenant:12', ['points' => 50]);
        $json = json_decode(json_encode($e), true);

        $this->assertSame(
            ['id', 'type', 'version', 'producer', 'application', 'tenant_reference', 'occurred_at', 'data'],
            array_keys($json),
        );
        $this->assertSame(1, $json['version']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $json['occurred_at']);
        $this->assertSame(['points' => 50], $json['data']);
    }

    public function test_round_trips_and_empty_data_is_an_object(): void
    {
        $e = EventEnvelope::fromArray([
            'id' => '01JD', 'type' => 'notification.message.delivered', 'version' => 2, 'producer' => 'n',
            'application' => 'a', 'tenant_reference' => 'a:tenant:1', 'occurred_at' => '2026-10-30T10:00:00Z', 'data' => [],
        ]);

        $this->assertSame('2026-10-30T10:00:00Z', $e->toArray()['occurred_at']);
        $this->assertStringContainsString('"data":{}', json_encode($e));
        $this->assertSame(2, EventEnvelope::fromArray($e->toArray() + [])->version);
    }

    public function test_rejects_bad_type_and_missing_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventEnvelope::make('badtype', 'p', 'a', 't');
    }

    public function test_from_array_requires_all_fields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventEnvelope::fromArray(['id' => 'x']);
    }
}
