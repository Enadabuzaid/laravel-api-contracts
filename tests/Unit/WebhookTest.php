<?php

namespace Enadstack\ApiContracts\Tests\Unit;

use Enadstack\ApiContracts\Tests\TestCase;
use Enadstack\ApiContracts\Webhooks\Exceptions\InvalidWebhookException;
use Enadstack\ApiContracts\Webhooks\WebhookSigner;
use Enadstack\ApiContracts\Webhooks\WebhookVerifier;

class WebhookTest extends TestCase
{
    public function test_signature_format_matches_standard(): void
    {
        $h = (new WebhookSigner)->headers('s3cret', '{"a":1}', 'evt_1', 1000);

        $this->assertSame('evt_1', $h['X-Webhook-Id']);
        $this->assertSame('1000', $h['X-Webhook-Timestamp']);
        $this->assertSame('sha256='.hash_hmac('sha256', '1000.{"a":1}', 's3cret'), $h['X-Webhook-Signature']);
    }

    public function test_valid_delivery_verifies(): void
    {
        $h = (new WebhookSigner)->headers('s3cret', 'body', null, 1000);

        $this->assertTrue((new WebhookVerifier)->isValid('s3cret', 'body', $h, 1100));
    }

    public function test_rejects_old_timestamp(): void
    {
        $h = (new WebhookSigner)->headers('s3cret', 'body', null, 1000);

        $this->expectException(InvalidWebhookException::class);
        (new WebhookVerifier)->verify('s3cret', 'body', $h, 1301);
    }

    public function test_rejects_tampered_body_wrong_secret_and_missing_headers(): void
    {
        $h = (new WebhookSigner)->headers('s3cret', 'body', null, 1000);
        $v = new WebhookVerifier;

        $this->assertFalse($v->isValid('s3cret', 'other', $h, 1000));
        $this->assertFalse($v->isValid('nope', 'body', $h, 1000));
        $this->assertFalse($v->isValid('s3cret', 'body', [], 1000));
    }
}
