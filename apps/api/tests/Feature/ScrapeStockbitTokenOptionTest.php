<?php

namespace Tests\Feature;

use App\Services\AssetProfileUpdater;
use App\Services\Stockbit\StockbitTokenResolver;
use App\Services\StockbitExodusClient;
use App\Support\StockbitTokenStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ScrapeStockbitTokenOptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Cache::flush();
        config()->set('stockbit.bearer', '');
        config()->set('stockbit.save_disk', 'local');
        config()->set('stockbit.save_dir', 'broker_summary');
        config()->set('stockbit.defaults.transaction_type', 'TRANSACTION_TYPE_NET');
        config()->set('stockbit.defaults.market_board', 'MARKET_BOARD_REGULER');
        config()->set('stockbit.defaults.investor_type', 'INVESTOR_TYPE_ALL');
        config()->set('stockbit.defaults.limit', 25);
        config()->set('stockbit.historical.period', 'HS_PERIOD_DAILY');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_token_option_is_persisted_and_used_by_scrape_command(): void
    {
        $token = $this->jwt(time() + 3600);

        $api = Mockery::mock(StockbitExodusClient::class);
        $api->shouldReceive('setBearer')->withAnyArgs()->andReturnNull();
        $api->shouldReceive('marketDetectors')->zeroOrMoreTimes()->andReturn(['items' => []]);
        $api->shouldReceive('historicalSummary')->zeroOrMoreTimes()->andReturn(['data' => []]);
        $api->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $api);
        $this->app->instance(AssetProfileUpdater::class, $this->makeProfileUpdaterMock());

        $exit = Artisan::call('stockbit:scrape', [
            'tickers' => ['BBCA'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--market-detector' => true,
            '--no-profile-sync' => true,
            '--token' => $token,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame($token, app(StockbitTokenStore::class)->get());
        $this->assertSame($token, Cache::get('stockbit.token'));
        $this->assertStringNotContainsString($token, Artisan::output());
    }

    public function test_expired_token_option_is_rejected_before_any_api_call(): void
    {
        $expired = $this->jwt(time() - 60);

        $api = Mockery::mock(StockbitExodusClient::class);
        $api->shouldReceive('setBearer')->never();
        $api->shouldReceive('marketDetectors')->never();
        $api->shouldReceive('historicalSummary')->never();
        $api->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $api);

        $exit = Artisan::call('stockbit:scrape', [
            'tickers' => ['BBCA'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--market-detector' => true,
            '--no-profile-sync' => true,
            '--token' => $expired,
        ]);

        $this->assertSame(1, $exit);
        $this->assertNull(app(StockbitTokenStore::class)->get());
        $this->assertStringContainsString('Provided --token is already expired', Artisan::output());
    }

    public function test_token_option_overrides_config_bearer(): void
    {
        $configToken = $this->jwt(time() + 7200);
        config()->set('stockbit.bearer', $configToken);
        $cliToken = $this->jwt(time() + 3600);

        $api = Mockery::mock(StockbitExodusClient::class);
        $api->shouldReceive('setBearer')->with($cliToken)->atLeast()->once();
        $api->shouldReceive('setBearer')->with($configToken)->never();
        $api->shouldReceive('marketDetectors')->zeroOrMoreTimes()->andReturn(['items' => []]);
        $api->shouldReceive('historicalSummary')->zeroOrMoreTimes()->andReturn(['data' => []]);
        $api->shouldReceive('tickerProfile')->never();

        $this->app->instance(StockbitExodusClient::class, $api);
        $this->app->instance(AssetProfileUpdater::class, $this->makeProfileUpdaterMock());

        $exit = Artisan::call('stockbit:scrape', [
            'tickers' => ['BBCA'],
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--market-detector' => true,
            '--no-profile-sync' => true,
            '--token' => $cliToken,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame($cliToken, app(StockbitTokenStore::class)->get(), 'CLI token should be persisted to the store.');
    }

    private function jwt(int $exp): string
    {
        $payload = rtrim(strtr(base64_encode(json_encode(['exp' => $exp])), '+/', '-_'), '=');

        return 'header.' . $payload . '.signature';
    }

    /**
     * Mock AssetProfileUpdater so the command does not touch the assets table
     * during pure token-plumbing tests.
     */
    private function makeProfileUpdaterMock(): AssetProfileUpdater
    {
        $mock = Mockery::mock(AssetProfileUpdater::class);
        $mock->shouldReceive('getIPODate')->withAnyArgs()->andReturnNull();
        $mock->shouldReceive('applyTickerProfileResponse')->withAnyArgs()->andReturn(['ok' => true, 'asset' => (object) ['profile_synced_at' => null], 'profile' => []]);

        return $mock;
    }
}
