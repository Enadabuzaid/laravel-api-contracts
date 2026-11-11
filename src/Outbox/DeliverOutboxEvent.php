<?php

namespace Enadstack\ApiContracts\Outbox;

use Enadstack\ApiContracts\Webhooks\WebhookSigner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one outbox row as signed webhooks to every matching subscription and
 * updates its status. Already-delivered URLs are skipped on retry.
 */
class DeliverOutboxEvent
{
    public function __construct(private readonly WebhookSigner $signer) {}

    public function handle(OutboxEvent $row): void
    {
        $subscriptions = collect(config('api-contracts.outbox.subscriptions', []))
            ->filter(fn (array $s) => $this->matches($s['events'] ?? ['*'], $row->type));

        $done = $row->delivered_urls ?? [];
        $errors = [];

        foreach ($subscriptions as $subscription) {
            $url = $subscription['url'];
            if (in_array($url, $done, true)) {
                continue;
            }

            try {
                $response = Http::timeout((int) config('api-contracts.outbox.timeout', 10))
                    ->withHeaders($this->signer->headers($subscription['secret'], $row->payload, $row->event_id))
                    ->withBody($row->payload, 'application/json')
                    ->post($url);

                $response->successful() ? $done[] = $url : $errors[] = "{$url}: HTTP {$response->status()}";
            } catch (Throwable $e) {
                $errors[] = "{$url}: {$e->getMessage()}";
            }
        }

        $attempts = $row->attempts + 1;
        $row->attempts = $attempts;
        $row->delivered_urls = $done;

        if ($errors === []) {
            $row->status = OutboxEvent::DELIVERED;
            $row->delivered_at = now();
            $row->last_error = null;
            $row->next_attempt_at = null;
        } elseif ($attempts >= (int) config('api-contracts.outbox.max_attempts', 8)) {
            $row->status = OutboxEvent::FAILED;
            $row->last_error = implode('; ', $errors);
            $row->next_attempt_at = null;
        } else {
            $row->last_error = implode('; ', $errors);
            $row->next_attempt_at = now()->addSeconds($this->backoff($attempts));
        }

        $row->save();
    }

    /** Exponential backoff: base * 2^(attempt-1), capped. */
    public function backoff(int $attempts): int
    {
        $base = (int) config('api-contracts.outbox.backoff_base', 30);
        $max = (int) config('api-contracts.outbox.backoff_max', 3600);

        return (int) min($max, $base * (2 ** ($attempts - 1)));
    }

    /** @param  array<int, string>  $patterns */
    private function matches(array $patterns, string $type): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $type)) {
                return true;
            }
        }

        return false;
    }
}
