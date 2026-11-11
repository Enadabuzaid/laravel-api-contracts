<?php

namespace Enadstack\ApiContracts\Tests\Feature;

use Enadstack\ApiContracts\Events\EventEnvelope;
use Enadstack\ApiContracts\Outbox\OutboxEvent;
use Enadstack\ApiContracts\Outbox\RecordOutboxEvent;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class OutboxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../database/migrations/create_outbox_events_table.php.stub')->up();

        config()->set('api-contracts.outbox.subscriptions', [
            ['url' => 'https://app.test/hook', 'secret' => 's3cret', 'events' => ['loyalty.*']],
            ['url' => 'https://other.test/hook', 'secret' => 'x', 'events' => ['notification.*']],
        ]);
    }

    private function event(string $type = 'loyalty.points.earned'): EventEnvelope
    {
        return EventEnvelope::make($type, 'loyalty-service', 'tafadel', 'tafadel:tenant:1', ['points' => 50]);
    }

    public function test_rollback_writes_nothing(): void
    {
        try {
            DB::transaction(function () {
                app(RecordOutboxEvent::class)->handle($this->event());
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, OutboxEvent::count());
    }

    public function test_commit_writes_pending_row_with_envelope(): void
    {
        $event = $this->event();
        DB::transaction(fn () => app(RecordOutboxEvent::class)->handle($event));

        $row = OutboxEvent::sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame($event->id, json_decode($row->payload, true)['id']);
    }

    public function test_process_delivers_signed_webhook_to_matching_subscription_only(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        $event = $this->event();
        app(RecordOutboxEvent::class)->handle($event);

        $this->artisan('outbox:process')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) use ($event) {
            $ts = $r->header('X-Webhook-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $ts.'.'.$r->body(), 's3cret');

            return $r->url() === 'https://app.test/hook'
                && $r->header('X-Webhook-Id')[0] === $event->id
                && $r->header('X-Webhook-Signature')[0] === $expected;
        });
        $row = OutboxEvent::sole();
        $this->assertSame('delivered', $row->status);
        $this->assertNotNull($row->delivered_at);
    }

    public function test_failure_retries_with_backoff_then_succeeds(): void
    {
        Http::fakeSequence()->push('', 500)->push('', 200);
        app(RecordOutboxEvent::class)->handle($this->event());

        $this->artisan('outbox:process');
        $row = OutboxEvent::sole();
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertTrue($row->next_attempt_at->isFuture());

        // not due yet: nothing sent
        $this->artisan('outbox:process');
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->artisan('outbox:process');
        $this->assertSame('delivered', OutboxEvent::sole()->status);
        Http::assertSentCount(2);
    }

    public function test_marks_failed_after_max_attempts(): void
    {
        config()->set('api-contracts.outbox.max_attempts', 2);
        Http::fake(['*' => Http::response('', 500)]);
        app(RecordOutboxEvent::class)->handle($this->event());

        $this->artisan('outbox:process');
        $this->travel(61)->seconds();
        $this->artisan('outbox:process');

        $row = OutboxEvent::sole();
        $this->assertSame('failed', $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertStringContainsString('HTTP 500', $row->last_error);
    }
}
