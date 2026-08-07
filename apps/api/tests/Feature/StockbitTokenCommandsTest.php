<?php

namespace Tests\Feature;

use App\Services\Stockbit\StockbitTokenResolver;
use App\Support\StockbitTokenStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockbitTokenCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
        config()->set('stockbit.bearer', '');
    }

    public function test_token_set_persists_token(): void
    {
        $token = $this->jwt(time() + 3600);

        $exit = Artisan::call('stockbit:token:set', ['token' => $token]);

        $this->assertSame(0, $exit);
        $this->assertSame($token, app(StockbitTokenStore::class)->get());
        $this->assertSame($token, Cache::get('stockbit.token'));

        $output = Artisan::output();
        $this->assertStringContainsString('Stockbit token saved.', $output);
        $this->assertStringContainsString('****'.substr($token, -4), $output);
        $this->assertStringNotContainsString($token, $output);
    }

    public function test_token_set_rejects_expired_token(): void
    {
        $expired = $this->jwt(time() - 60);

        $exit = Artisan::call('stockbit:token:set', ['token' => $expired]);

        $this->assertSame(1, $exit);
        $this->assertNull(app(StockbitTokenStore::class)->get());
        $this->assertNull(Cache::get('stockbit.token'));

        $this->assertStringContainsString('already expired', Artisan::output());
    }

    public function test_token_set_rejects_non_jwt_input(): void
    {
        $exit = Artisan::call('stockbit:token:set', ['token' => 'not-a-jwt']);

        $this->assertSame(1, $exit);
        $this->assertNull(app(StockbitTokenStore::class)->get());
        $this->assertStringContainsString('does not look like a JWT', Artisan::output());
    }

    public function test_token_status_reports_no_token(): void
    {
        $exit = Artisan::call('stockbit:token:status');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No Stockbit bearer token is currently available.', Artisan::output());
    }

    public function test_token_status_reports_active_token_without_disclosing_it(): void
    {
        $token = $this->jwt(time() + 3600);
        app(StockbitTokenResolver::class)->persist($token);

        $exit = Artisan::call('stockbit:token:status');

        $this->assertSame(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('Stockbit token is configured.', $output);
        $this->assertStringContainsString('source:      '.StockbitTokenResolver::SOURCE_STORE, $output);
        $this->assertStringContainsString('****'.substr($token, -4), $output);
        $this->assertStringNotContainsString($token, $output);
    }

    public function test_token_clear_force_removes_store_and_cache(): void
    {
        app(StockbitTokenResolver::class)->persist($this->jwt(time() + 3600));

        $exit = Artisan::call('stockbit:token:clear', ['--force' => true]);

        $this->assertSame(0, $exit);
        $this->assertNull(app(StockbitTokenStore::class)->get());
        $this->assertNull(Cache::get('stockbit.token'));
        $this->assertStringContainsString('Stockbit token cleared', Artisan::output());
    }

    private function jwt(int $exp): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $exp])), '+/', '-_'), '=');

        return 'header.'.$payload.'.signature';
    }
}
