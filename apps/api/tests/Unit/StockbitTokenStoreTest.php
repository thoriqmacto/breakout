<?php

namespace Tests\Unit;

use App\Support\StockbitTokenStore;
use Illuminate\Support\Facades\Crypt;
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
        $store = new StockbitTokenStore;
        $token = $this->jwtWithExpiry(time() + 3600);

        $store->put($token);

        $this->assertSame($token, $store->get());
        Storage::disk('local')->assertExists('stockbit_token.json');
    }

    public function test_it_clears_expired_token(): void
    {
        $store = new StockbitTokenStore;
        $expired = $this->jwtWithExpiry(time() - 3600);

        $store->put($expired);

        $this->assertNull($store->get());
        Storage::disk('local')->assertMissing('stockbit_token.json');
    }

    public function test_it_writes_token_in_encrypted_v2_format(): void
    {
        $store = new StockbitTokenStore;
        $token = $this->jwtWithExpiry(time() + 3600);

        $store->put($token);

        $contents = Storage::disk('local')->get('stockbit_token.json');
        $decoded = json_decode($contents, true);

        $this->assertSame(2, $decoded['v']);
        $this->assertArrayHasKey('bearer_encrypted', $decoded);
        $this->assertArrayNotHasKey('bearer', $decoded);
        $this->assertNotSame($token, $decoded['bearer_encrypted']);
        $this->assertSame($token, Crypt::decryptString($decoded['bearer_encrypted']));
    }

    public function test_it_reads_legacy_plaintext_payload(): void
    {
        $token = $this->jwtWithExpiry(time() + 3600);
        Storage::disk('local')->put(
            'stockbit_token.json',
            json_encode(['bearer' => $token])
        );

        $store = new StockbitTokenStore;

        $this->assertSame($token, $store->get());
    }

    public function test_it_reads_legacy_encrypted_bearer_field(): void
    {
        $token = $this->jwtWithExpiry(time() + 3600);
        Storage::disk('local')->put(
            'stockbit_token.json',
            json_encode(['bearer' => Crypt::encryptString($token)])
        );

        $store = new StockbitTokenStore;

        $this->assertSame($token, $store->get());
    }

    private function jwtWithExpiry(int $timestamp): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $timestamp])), '+/', '-_'), '=');

        return 'header.'.$payload.'.signature';
    }
}
