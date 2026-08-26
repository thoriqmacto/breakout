<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Services\AssetProfileUpdater;
use App\Services\StockbitExodusClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AssetProfileUpdaterTest extends TestCase
{
    use RefreshDatabase;

    private array $sampleProfile = [
        'address' => [
            [
                'id' => '104',
                'email' => ['corsec@bumiresources.com'],
                'fax' => '(021) 5794 2070',
                'npwp' => '01.122.101.7-054.000',
                'phone' => '(021) 5794 2080',
                'website' => 'http://www.bumiresources.com ',
                'key' => '',
                'lastupdate' => '2025-06-23T15:40:21+07:00',
                'value' => '',
                'office' => 'Bakrie Tower Lt. 12, Komplek Rasuna Epicentrum, Jl. H.R. Rasuna Said, Jakarta Selatan 12940',
            ],
        ],
        'background' => 'PT Bumi Resources Tbk merupakan perusahaan yang bergerak di bidang pertambangan batubara dan minyak bumi.',
        'history' => [
            'amount' => '-',
            'board' => 'Papan Utama',
            'date' => '30 Jul 1990',
            'price' => '4,500',
            'registrar' => '',
            'shares' => '',
            'underwriters' => [],
            'administrative_bureau' => 'PT. Ficomindo Buana Registrar',
            'free_float' => '28.88%',
        ],
        'key_executive' => [
            'commissioner' => [
                ['id' => '15967189', 'key' => 'Commissioner', 'value' => 'ADHIKA ANDRAYUDHA BAKRIE'],
            ],
        ],
        'secretary' => [
            [
                'id' => '12528955',
                'value' => '{"name":"Dileep Srivastava"}',
            ],
        ],
        'shareholder' => [
            [
                'percentage' => '45.78%',
                'name' => 'MACH ENERGY (HONGKONG) LIMITED',
                'value' => '170.00 B',
                'badges' => ['pengendali'],
                'id' => '0',
            ],
        ],
        'subsidiary' => [
            [
                'company' => 'PT Kaltim Prima Coal',
                'percentage' => '51.00%',
                'types' => 'Pertambangan Batubara',
                'value' => '1071409180',
            ],
        ],
        'profile' => [
            'fund_type' => [
                'value' => '',
                'info' => '',
            ],
        ],
        'fee' => [],
        'asset_allocation' => [],
        'shareholder_reksa' => [],
        'pdf' => [],
        'shareholder_numbers' => [
            [
                'shareholder_date' => '29 Aug 2025',
                'total_share' => '136,653',
                'change' => -1274,
                'change_formatted' => '(-1,274)',
            ],
        ],
        'badges' => ['direktur', 'komisaris'],
        'top_holdings' => [],
        'shareholder_director_commissioner' => [],
        'listing_information' => [
            'exercise_start_date' => '',
            'exercise_end_date' => '',
            'exercise_price' => 0,
            'expire_date' => '',
            'listing_date' => '',
            'foreign_percentage' => ['raw' => 0, 'formatted' => ''],
            'local_percentage' => ['raw' => 0, 'formatted' => ''],
            'number_of_securities' => 0,
            'total_shares' => 0,
        ],
    ];

    public function test_it_updates_asset_from_ticker_profile(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-09-01 08:00:00'));

        // AssetProfileUpdater writes the seeder JSON to
        // database/seeders/data/profiles/{SYMBOL}_profile.json, which is
        // tracked. This test used to run as BUMI, so every `php artisan test`
        // deleted the real BUMI_profile.json from the working tree -- and a
        // failure between the delete and the cleanup left fabricated test data
        // in its place. A symbol that is not a real ticker cannot collide with
        // anything committed.
        $symbol = 'ZZTEST';

        $asset = Asset::create([
            'symbol' => $symbol,
            'name' => $symbol,
        ]);

        $profilePath = database_path("seeders/data/profiles/{$symbol}_profile.json");
        $this->assertFileDoesNotExist($profilePath, 'The probe symbol must not be a committed profile.');

        $client = $this->createMock(StockbitExodusClient::class);
        $client->expects($this->once())
            ->method('tickerProfile')
            ->with($symbol)
            ->willReturn(['data' => $this->sampleProfile]);

        $service = new AssetProfileUpdater($client);

        try {
            $result = $service->sync($asset);

            $this->assertTrue($result['ok']);
            $fresh = $asset->fresh();
            $this->assertEquals($this->sampleProfile['address'], $fresh->address);
            $this->assertSame($this->sampleProfile['background'], $fresh->background);
            $this->assertEquals($this->sampleProfile['history'], $fresh->history);
            $this->assertEquals($this->sampleProfile, $fresh->ticker_profile_payload);
            $this->assertEquals(4500.0, $fresh->ipo_price);
            $this->assertEquals(28.88, $fresh->float);
            $this->assertTrue($fresh->profile_synced_at->eq(Carbon::now()));

            $this->assertFileExists($profilePath);
            $payload = json_decode(File::get($profilePath), true);
            $this->assertSame($symbol, $payload['symbol']);
            $this->assertSame($symbol, $payload['name']);
            $this->assertSame(Carbon::now()->toIso8601String(), $payload['profile_synced_at']);
            $this->assertEquals($this->sampleProfile, $payload['ticker_profile_payload']);
        } finally {
            // finally, so a failed assertion above still leaves the tree clean.
            File::delete($profilePath);
            Carbon::setTestNow();
        }
    }

    public function test_it_returns_error_when_client_reports_issue(): void
    {
        $client = $this->createMock(StockbitExodusClient::class);
        $client->expects($this->once())
            ->method('tickerProfile')
            ->with('BUMI')
            ->willReturn(['error' => 'unauthorized', 'message' => 'token expired']);

        $service = new AssetProfileUpdater($client);
        $result = $service->sync('BUMI');

        $this->assertFalse($result['ok']);
        $this->assertSame('unauthorized', $result['error']);
        $this->assertSame('token expired', $result['message']);
    }
}
