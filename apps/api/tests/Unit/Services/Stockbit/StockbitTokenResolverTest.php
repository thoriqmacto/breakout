<?php

namespace Tests\Unit\Services\Stockbit;

use App\Services\Stockbit\StockbitTokenResolver;
use App\Support\StockbitTokenStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StockbitTokenResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
        config()->set('stockbit.bearer', '');
    }

    public function test_cli_option_takes_priority_over_other_sources(): void
    {
        $cli = $this->jwt(time() + 3600);
        $config = $this->jwt(time() + 7200);
        $store = $this->jwt(time() + 1800);
        config()->set('stockbit.bearer', $config);
        app(StockbitTokenStore::class)->put($store);
        Cache::put('stockbit.token', $this->jwt(time() + 600), 600);

        $resolver = app(StockbitTokenResolver::class);
        $resolved = $resolver->resolveWithSource($cli);

        $this->assertSame($cli, $resolved['token']);
        $this->assertSame(StockbitTokenResolver::SOURCE_OPTION, $resolved['source']);
    }

    public function test_config_is_used_when_cli_is_missing(): void
    {
        $config = $this->jwt(time() + 3600);
        config()->set('stockbit.bearer', $config);
        app(StockbitTokenStore::class)->put($this->jwt(time() + 1800));

        $resolved = app(StockbitTokenResolver::class)->resolveWithSource();

        $this->assertSame($config, $resolved['token']);
        $this->assertSame(StockbitTokenResolver::SOURCE_CONFIG, $resolved['source']);
    }

    public function test_store_is_used_when_cli_and_config_missing(): void
    {
        $stored = $this->jwt(time() + 3600);
        app(StockbitTokenStore::class)->put($stored);

        $resolved = app(StockbitTokenResolver::class)->resolveWithSource();

        $this->assertSame($stored, $resolved['token']);
        $this->assertSame(StockbitTokenResolver::SOURCE_STORE, $resolved['source']);
    }

    public function test_cache_is_last_fallback(): void
    {
        $cached = $this->jwt(time() + 3600);
        Cache::put('stockbit.token', $cached, 3600);

        $resolved = app(StockbitTokenResolver::class)->resolveWithSource();

        $this->assertSame($cached, $resolved['token']);
        $this->assertSame(StockbitTokenResolver::SOURCE_CACHE, $resolved['source']);
    }

    public function test_returns_none_when_no_source_has_a_token(): void
    {
        $resolved = app(StockbitTokenResolver::class)->resolveWithSource();

        $this->assertNull($resolved['token']);
        $this->assertSame(StockbitTokenResolver::SOURCE_NONE, $resolved['source']);
    }

    public function test_expired_cli_token_is_skipped_and_falls_through(): void
    {
        $config = $this->jwt(time() + 3600);
        config()->set('stockbit.bearer', $config);
        $expired = $this->jwt(time() - 60);

        $resolved = app(StockbitTokenResolver::class)->resolveWithSource($expired);

        $this->assertSame($config, $resolved['token']);
        $this->assertSame(StockbitTokenResolver::SOURCE_CONFIG, $resolved['source']);
    }

    public function test_expired_token_in_store_is_purged_and_falls_through(): void
    {
        app(StockbitTokenStore::class)->put($this->jwt(time() - 60));
        $cached = $this->jwt(time() + 3600);
        Cache::put('stockbit.token', $cached, 3600);

        $resolved = app(StockbitTokenResolver::class)->resolveWithSource();

        $this->assertSame($cached, $resolved['token']);
        $this->assertSame(StockbitTokenResolver::SOURCE_CACHE, $resolved['source']);
    }

    public function test_persist_writes_to_store_and_cache(): void
    {
        $token = $this->jwt(time() + 3600);

        app(StockbitTokenResolver::class)->persist($token);

        $this->assertSame($token, app(StockbitTokenStore::class)->get());
        $this->assertSame($token, Cache::get('stockbit.token'));
    }

    public function test_forget_clears_store_and_cache(): void
    {
        $token = $this->jwt(time() + 3600);
        $resolver = app(StockbitTokenResolver::class);
        $resolver->persist($token);

        $resolver->forget();

        $this->assertNull(app(StockbitTokenStore::class)->get());
        $this->assertNull(Cache::get('stockbit.token'));
    }

    private function jwt(int $exp): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $exp])), '+/', '-_'), '=');

        return 'header.'.$payload.'.signature';
    }
}
