<?php

namespace Tests\Unit\Services\Automation;

use App\Services\Automation\CommandRegistry;
use Illuminate\Contracts\Console\Kernel;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The registry is the boundary between the browser and Artisan. Everything
 * here is about what it refuses.
 */
class CommandRegistryTest extends TestCase
{
    private CommandRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new CommandRegistry;
    }

    public function test_only_allowlisted_commands_are_accepted(): void
    {
        $this->assertTrue($this->registry->has('automation:ohlcv-daily'));
        $this->assertFalse($this->registry->has('migrate:fresh'));

        $this->expectException(InvalidArgumentException::class);
        $this->registry->validate('migrate:fresh', []);
    }

    public function test_an_undeclared_option_is_refused(): void
    {
        $this->expectExceptionMessage('is not accepted by');

        $this->registry->validate('trading-calendar:build', [
            'arguments' => [],
            'options' => ['holiday-file' => '/etc/passwd'],
        ]);
    }

    public function test_the_bearer_token_can_never_be_scheduled(): void
    {
        foreach (['token', 'bearer', 'secret', 'password', 'api-key', 'authorization'] as $name) {
            try {
                $this->registry->validate('stockbit:scrape', [
                    'arguments' => [],
                    'options' => [$name => 'anything'],
                ]);

                $this->fail(sprintf('"%s" was accepted as a schedulable parameter.', $name));
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('may never be stored', $exception->getMessage());
            }
        }
    }

    public function test_stockbit_scrape_does_not_declare_a_token_option_at_all(): void
    {
        $definition = $this->registry->definition('stockbit:scrape');

        $this->assertArrayNotHasKey('token', $definition['options']);
    }

    public function test_a_date_option_must_be_a_real_date(): void
    {
        $validated = $this->registry->validate('trading-calendar:build', [
            'arguments' => [],
            'options' => ['from' => '2026-02-28'],
        ]);

        $this->assertSame('2026-02-28', $validated['options']['from']);

        $this->expectExceptionMessage('not a real calendar date');
        $this->registry->validate('trading-calendar:build', [
            'arguments' => [],
            'options' => ['from' => '2026-02-30'],
        ]);
    }

    public function test_a_symbol_list_rejects_anything_that_is_not_a_ticker(): void
    {
        $validated = $this->registry->validate('stockbit:scrape', [
            'arguments' => ['tickers' => ['bbca', ' ANTM ', 'BBCA']],
            'options' => [],
        ]);

        // Normalised, de-duplicated and sorted.
        $this->assertSame(['ANTM', 'BBCA'], $validated['arguments']['tickers']);

        foreach (['BBCA; rm -rf /', '$(whoami)', '../../etc/passwd', '--all'] as $hostile) {
            try {
                $this->registry->validate('stockbit:scrape', [
                    'arguments' => ['tickers' => [$hostile]],
                    'options' => [],
                ]);

                $this->fail(sprintf('"%s" was accepted as a ticker symbol.', $hostile));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_enum_option_only_accepts_declared_values(): void
    {
        $this->expectExceptionMessage('must be one of');

        $this->registry->validate('bars:mirror-push', [
            'arguments' => [],
            'options' => ['disk' => '/dev/null'],
        ]);
    }

    public function test_artisan_parameters_are_structured_not_a_command_line(): void
    {
        $parameters = $this->registry->toArtisanParameters('stockbit:scrape', [
            'arguments' => ['tickers' => ['BBCA', 'ANTM']],
            'options' => ['historical' => true, 'from' => '2026-08-28', 'no-persist' => false],
        ]);

        $this->assertSame(['ANTM', 'BBCA'], $parameters['tickers']);
        $this->assertTrue($parameters['--historical']);
        $this->assertSame('2026-08-28', $parameters['--from']);
        // A false flag is omitted; Symfony treats presence as truth, so
        // passing it would turn "off" into "on".
        $this->assertArrayNotHasKey('--no-persist', $parameters);
    }

    public function test_the_preview_is_readable_but_is_not_what_gets_executed(): void
    {
        $preview = $this->registry->preview('stockbit:scrape', [
            'arguments' => ['tickers' => ['BBCA']],
            'options' => ['historical' => true, 'from' => '2026-08-28', 'to' => '2026-08-28'],
        ]);

        $this->assertSame(
            'php artisan stockbit:scrape BBCA --from=2026-08-28 --historical --to=2026-08-28',
            $preview,
        );
    }

    public function test_empty_values_are_dropped_rather_than_passed_as_blanks(): void
    {
        $validated = $this->registry->validate('trading-calendar:build', [
            'arguments' => [],
            'options' => ['from' => '', 'to' => null],
        ]);

        $this->assertSame([], $validated['options']);
    }

    public function test_bulk_stockbit_commands_are_identified(): void
    {
        $this->assertTrue($this->registry->isStockbitBulk('automation:ohlcv-daily'));
        $this->assertTrue($this->registry->isStockbitBulk('automation:broker-summary-weekly'));
        $this->assertFalse($this->registry->isStockbitBulk('automation:token-check'));
        $this->assertFalse($this->registry->isStockbitBulk('bars:mirror-push'));
    }

    public function test_every_allowlisted_command_actually_exists(): void
    {
        $registered = array_keys($this->app[Kernel::class]->all());

        foreach ($this->registry->names() as $name) {
            $this->assertContains(
                $name,
                $registered,
                sprintf('"%s" is on the allowlist but is not a registered Artisan command.', $name),
            );
        }
    }
}
