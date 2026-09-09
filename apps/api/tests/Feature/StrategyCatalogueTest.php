<?php

namespace Tests\Feature;

use App\Models\Strategy;
use App\Models\User;
use App\Services\Strategies\StrategyCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One list of built-in strategies, and everything reads it.
 *
 * The list used to live inline in AssetBacktest::handle(), which meant the
 * only way to answer "how many strategies are there" was to read a command --
 * so the overview card printed 6 as a string literal. It was right when it was
 * written, and nothing would have made it wrong out loud.
 */
class StrategyCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backtest_command_resolves_strategies_through_the_catalogue(): void
    {
        $catalogue = app(StrategyCatalogue::class);

        // The keys are the command's contract; renaming one silently breaks
        // every script and cron that passes it.
        $this->assertSame(
            ['DonchBO', 'AtrBO', 'RocMomentum', 'MACross', 'RsiReversal', 'SR_BO', 'HLSLBreakout'],
            $catalogue->keys(),
        );

        // The alias the command has always accepted still resolves.
        $this->assertSame('HLSLBreakout', $catalogue->find('HLSL')['key']);
        $this->assertSame('DonchBO', $catalogue->find('donchbo')['key']);
        $this->assertNull($catalogue->find('NoSuchStrategy'));
    }

    /**
     * An unknown strategy lists what would have worked.
     */
    public function test_an_unknown_strategy_names_the_available_ones(): void
    {
        $this->artisan('asset:backtest', ['--sym' => ['AAA'], '--strategy' => 'NOPE'])
            ->expectsOutputToContain('Unknown strategy: NOPE')
            ->expectsOutputToContain('DonchBO')
            ->assertExitCode(1);
    }

    public function test_the_endpoint_serves_the_catalogue_and_a_real_user_count(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Strategy::create([
            'user_id' => $user->id,
            'name' => 'Mine',
            'rules' => ['all' => []],
            'is_active' => true,
            'visibility' => 'private',
        ]);

        $response = $this->getJson('/api/v1/strategies/built-in')->assertOk();

        $data = $response->json('data');

        $this->assertSame(7, $data['built_in_count']);
        $this->assertCount(7, $data['built_in']);
        $this->assertSame(1, $data['user_count']);

        $first = $data['built_in'][0];

        $this->assertSame('DonchBO', $first['key']);
        $this->assertArrayHasKey('parameters', $first);

        // The class name is an internal detail. Exposing it invites callers to
        // start addressing strategies by it, and it is of no use to the page.
        $this->assertArrayNotHasKey('class', $first);
    }

    /**
     * The count the dashboard shows has to come from the catalogue, so that
     * adding a strategy changes the number without anyone editing the page.
     */
    public function test_the_count_follows_the_catalogue(): void
    {
        config(['strategy_catalogue.built_in' => [
            ['key' => 'OnlyOne', 'name' => 'Only one', 'class' => 'X', 'summary' => '', 'parameters' => []],
        ]]);

        $this->assertSame(1, app(StrategyCatalogue::class)->count());
    }
}
