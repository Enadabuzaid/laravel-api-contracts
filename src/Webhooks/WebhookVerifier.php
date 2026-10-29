<?php

namespace Enadstack\ApiContracts\Webhooks;

use Enadstack\ApiContracts\Webhooks\Exceptions\InvalidWebhookException;

class WebhookVerifier
{
    public function __construct(private readonly int $tolerance = 300) {}

    /**
     * @param  array<string, string|null>  $headers  case-insensitive lookup
     *
     * @throws InvalidWebhookException
     */
    public function verify(string $secret, string $body, array $headers, ?int $now = null): void
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $timestamp = $headers['x-webhook-timestamp'] ?? null;
        $signature = $headers['x-webhook-signature'] ?? null;

        if (empty($headers['x-webhook-id']) || $timestamp === null || $signature === null) {
            throw new InvalidWebhookException('Missing webhook headers.');
        }

        if (! ctype_digit((string) $timestamp) || abs(($now ?? time()) - (int) $timestamp) > $this->tolerance) {
            throw new InvalidWebhookException('Webhook timestamp outside the allowed tolerance.');
        }

        if (! hash_equals(WebhookSigner::signature($secret, (string) $timestamp, $body), (string) $signature)) {
            throw new InvalidWebhookException('Invalid webhook signature.');
        }
    }

    public function isValid(string $secret, string $body, array $headers, ?int $now = null): bool
    {
        try {
            $this->verify($secret, $body, $headers, $now);

            return true;
        } catch (InvalidWebhookException) {
            return false;
        }
    }
}
