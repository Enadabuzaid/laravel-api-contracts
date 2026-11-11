<?php

namespace Enadstack\ApiContracts\Webhooks;

use Illuminate\Support\Str;

class WebhookSigner
{
    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Headers to send with a webhook delivery.
     *
     * @return array{X-Webhook-Id: string, X-Webhook-Timestamp: string, X-Webhook-Signature: string}
     */
    public function headers(string $secret, string $body, ?string $id = null, ?int $timestamp = null): array
    {
        $timestamp = (string) ($timestamp ?? time());

        return [
            'X-Webhook-Id' => $id ?? (string) Str::ulid(),
            'X-Webhook-Timestamp' => $timestamp,
            'X-Webhook-Signature' => self::signature($secret, $timestamp, $body),
        ];
    }
}
