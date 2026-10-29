<?php

namespace Enadstack\ApiContracts\Console;

use Enadstack\ApiContracts\Outbox\DeliverOutboxEvent;
use Enadstack\ApiContracts\Outbox\OutboxEvent;
use Illuminate\Console\Command;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process {--limit= : Max rows per run}';

    protected $description = 'Deliver pending outbox events as signed webhooks';

    public function handle(DeliverOutboxEvent $deliver): int
    {
        $limit = (int) ($this->option('limit') ?: config('api-contracts.outbox.batch', 100));

        $rows = OutboxEvent::query()
            ->where('status', OutboxEvent::PENDING)
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $deliver->handle($row);
        }

        $this->info("Processed {$rows->count()} outbox event(s).");

        return self::SUCCESS;
    }
}
