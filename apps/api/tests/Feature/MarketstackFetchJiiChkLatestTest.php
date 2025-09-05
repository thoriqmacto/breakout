<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketstackFetchJiiChkLatestTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_skips_api_call_when_all_symbols_latest(): void
    {
        putenv('MARKETSTACK_KEY=test-key');
        $_ENV['MARKETSTACK_KEY'] = 'test-key';

        config(['csv.index_symbols' => ['AAA']]);
        $csvDir = database_path('seeders/data/test-historical');
        if (!is_dir($csvDir)) {
            mkdir($csvDir, 0755, true);
        }
        file_put_contents($csvDir . '/AAA.csv', "date,open,high,low,close,volume\n2024-01-01,1,2,1,2,100\n");
        config(['csv.seed_dir' => $csvDir]);

        Http::fake();

        $this->artisan('marketstack:fetch-jii', ['--chk-latest' => '2024-01-01'])
            ->expectsOutput('All symbols are up to date. No API call required.')
            ->assertExitCode(0);
    }
}
