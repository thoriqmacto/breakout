<?php

namespace Tests\Feature\Automation;

use App\Models\AutomationAlert;
use App\Services\Indexes\IndexCatalogReader;
use App\Services\Indexes\IndexCatalogReadException;
use App\Services\Indexes\IndexMembershipSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The command has to work when the page does not.
 *
 * A scraped list and a pasted one take the same path on purpose: the markup of
 * somebody else's catalogue page will change, and when it does the feature
 * must degrade to "paste the list" rather than to "wait for a patch". These
 * tests cover both entrances and the alert that says which one is needed.
 */
class IndexSyncCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    private function members(int $count): array
    {
        $symbols = [];

        for ($index = 0; $index < $count; $index++) {
            $symbols[] = 'AA'.str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        }

        return $symbols;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('market_indexes.default', 'TEST70');
        config()->set('market_indexes.indexes', [
            'TEST70' => [
                'name' => 'Test 70',
                'url' => 'https://example.test/indeks/test70',
                'expected_size' => 70,
                'min_members' => 40,
            ],
        ]);
    }

    public function test_a_supplied_list_is_applied_without_opening_a_browser(): void
    {
        // Bound into the container as a reader that would fail loudly: the
        // point is that --symbols never reaches it.
        $this->app->bind(IndexCatalogReader::class, function () {
            return new class extends IndexCatalogReader
            {
                public function read(string $url, array $options = []): array
                {
                    throw new \RuntimeException('the browser must not be used for a supplied list');
                }
            };
        });

        $this->artisan('automation:index-sync', ['--symbols' => implode(',', $this->members(45))])
            ->assertExitCode(0);

        $this->assertCount(45, app(IndexMembershipSync::class)->currentSymbols('TEST70'));
        $this->assertDatabaseHas('market_indexes', ['code' => 'TEST70', 'last_source' => 'manual', 'member_count' => 45]);
    }

    public function test_a_dry_run_reports_the_change_and_writes_nothing(): void
    {
        $this->artisan('automation:index-sync', ['--symbols' => implode(' ', $this->members(45))])
            ->assertExitCode(0);

        $next = $this->members(45);
        $next[] = 'ZZZZ';

        $this->artisan('automation:index-sync', [
            '--symbols' => implode(',', $next),
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertNotContains('ZZZZ', app(IndexMembershipSync::class)->currentSymbols('TEST70'));
    }

    public function test_a_file_is_read_and_a_missing_one_is_reported(): void
    {
        $path = storage_path('app/index-sync-test.txt');
        File::put($path, implode(PHP_EOL, $this->members(45)));

        try {
            $this->artisan('automation:index-sync', ['--file' => $path])->assertExitCode(0);
        } finally {
            File::delete($path);
        }

        $this->assertCount(45, app(IndexMembershipSync::class)->currentSymbols('TEST70'));

        $this->artisan('automation:index-sync', ['--file' => storage_path('app/nowhere.txt')])
            ->assertExitCode(2);
    }

    public function test_an_unreadable_page_raises_an_alert_and_leaves_membership_alone(): void
    {
        $this->artisan('automation:index-sync', ['--symbols' => implode(',', $this->members(45))]);

        $this->app->bind(IndexCatalogReader::class, function () {
            return new class extends IndexCatalogReader
            {
                public function read(string $url, array $options = []): array
                {
                    throw new IndexCatalogReadException(
                        IndexCatalogReadException::NO_SYMBOLS_FOUND,
                        'The page loaded but carried nothing ticker-shaped.',
                        ['rows' => 0],
                    );
                }
            };
        });

        $this->artisan('automation:index-sync')->assertExitCode(1);

        $this->assertCount(45, app(IndexMembershipSync::class)->currentSymbols('TEST70'));

        $alert = AutomationAlert::where('type', AutomationAlert::TYPE_INDEX_MEMBERSHIP)->first();

        $this->assertNotNull($alert);
        $this->assertSame('TEST70', $alert->key);
        // The remedy has to be in the alert: the reader being broken is
        // exactly when nobody can look it up in the code.
        $this->assertStringContainsString('automation:index-sync', $alert->message);
    }

    public function test_a_refused_list_fails_the_run_and_says_why(): void
    {
        $this->artisan('automation:index-sync', ['--symbols' => implode(',', $this->members(45))]);

        $this->artisan('automation:index-sync', ['--symbols' => 'AA00,AA01,AA02'])
            ->assertExitCode(1);

        $this->assertCount(45, app(IndexMembershipSync::class)->currentSymbols('TEST70'));

        $alert = AutomationAlert::where('type', AutomationAlert::TYPE_INDEX_MEMBERSHIP)->first();

        $this->assertNotNull($alert);
        $this->assertStringContainsString('--force', $alert->message);
    }

    public function test_a_successful_run_clears_a_standing_alert(): void
    {
        AutomationAlert::create([
            'type' => AutomationAlert::TYPE_INDEX_MEMBERSHIP,
            'key' => 'TEST70',
            'severity' => AutomationAlert::SEVERITY_WARNING,
            'title' => 'stale',
            'message' => 'stale',
        ]);

        $this->artisan('automation:index-sync', ['--symbols' => implode(',', $this->members(45))])
            ->assertExitCode(0);

        $this->assertNotNull(
            AutomationAlert::where('type', AutomationAlert::TYPE_INDEX_MEMBERSHIP)->first()?->resolved_at,
        );
    }

    public function test_an_unknown_index_is_refused_before_anything_runs(): void
    {
        $this->artisan('automation:index-sync', ['--index' => 'NOPE', '--symbols' => 'AAAA'])
            ->assertExitCode(2);

        $this->assertDatabaseCount('index_memberships', 0);
    }
}
