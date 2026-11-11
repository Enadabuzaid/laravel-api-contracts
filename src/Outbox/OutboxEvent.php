<?php

namespace Enadstack\ApiContracts\Outbox;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $event_id
 * @property string $type
 * @property string $payload
 * @property string $status
 * @property int $attempts
 * @property array<int, string>|null $delivered_urls
 */
class OutboxEvent extends Model
{
    public const PENDING = 'pending';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    protected $table = 'outbox_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'delivered_urls' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
