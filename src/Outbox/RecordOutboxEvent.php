<?php

namespace Enadstack\ApiContracts\Outbox;

use Enadstack\ApiContracts\Events\EventEnvelope;
use Illuminate\Support\Facades\DB;

/**
 * Writes an event envelope to the outbox. Call it inside the caller's
 * DB::transaction so the event commits or rolls back with the domain change.
 */
class RecordOutboxEvent
{
    public function __invoke(EventEnvelope $event): OutboxEvent
    {
        return $this->handle($event);
    }

    public function handle(EventEnvelope $event): OutboxEvent
    {
        return DB::transaction(fn () => OutboxEvent::create([
            'event_id' => $event->id,
            'type' => $event->type,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
            'status' => OutboxEvent::PENDING,
            'next_attempt_at' => now(),
        ]));
    }
}
