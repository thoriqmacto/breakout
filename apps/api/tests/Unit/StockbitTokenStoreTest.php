<?php

namespace Tests\Unit;

use App\Support\StockbitTokenStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockbitTokenStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_persists_and_returns_token_until_expired(): void
    {
        $store = new StockbitTokenStore();
        $token = $this->jwtWithExpiry(time() + 3600);

        $store->put($token);

        $this->assertSame($token, $store->get());
        Storage::disk('local')->assertExists('stockbit_token.json');
    }

    public function test_it_clears_expired_token(): void
    {
        $store = new StockbitTokenStore();
        $expired = $this->jwtWithExpiry(time() - 3600);

        $store->put($expired);

        $this->assertNull($store->get());
        Storage::disk('local')->assertMissing('stockbit_token.json');
    }

    private function jwtWithExpiry(int $timestamp): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $timestamp])), '+/', '-_'), '=');

        return 'header.' . $payload . '.signature';
    }
}
