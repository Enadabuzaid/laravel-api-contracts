<?php

namespace Enadstack\ApiContracts\Tests\Feature;

use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Support\Facades\Route;

class IdempotencyTest extends TestCase
{
    private int $hits = 0;

    protected function setUp(): void
    {
        parent::setUp();

        (require __DIR__.'/../../database/migrations/create_idempotency_keys_table.php.stub')->up();

        Route::middleware('idempotency')->post('/charge', function () {
            $this->hits++;

            return response()->json(['n' => $this->hits], 201);
        });
    }

    public function test_replay_returns_same_body_and_header_without_rerunning(): void
    {
        $first = $this->postJson('/charge', ['a' => 1], ['Idempotency-Key' => 'k1']);
        $second = $this->postJson('/charge', ['a' => 1], ['Idempotency-Key' => 'k1']);

        $first->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $second->assertStatus(201)->assertExactJson(['n' => 1])->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, $this->hits);
    }

    public function test_same_key_with_different_body_is_422(): void
    {
        $this->postJson('/charge', ['a' => 1], ['Idempotency-Key' => 'k1']);

        $this->postJson('/charge', ['a' => 2], ['Idempotency-Key' => 'k1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_keys_are_scoped_per_caller(): void
    {
        $this->postJson('/charge', [], ['Idempotency-Key' => 'k1', 'Authorization' => 'Bearer a']);
        $this->postJson('/charge', [], ['Idempotency-Key' => 'k1', 'Authorization' => 'Bearer b'])
            ->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, $this->hits);
    }

    public function test_without_key_passes_through(): void
    {
        $this->postJson('/charge');
        $this->postJson('/charge');

        $this->assertSame(2, $this->hits);
    }

    public function test_expired_key_is_executed_again(): void
    {
        $this->postJson('/charge', [], ['Idempotency-Key' => 'k1']);
        $this->travel(25)->hours();
        $this->postJson('/charge', [], ['Idempotency-Key' => 'k1'])->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, $this->hits);
    }
}
