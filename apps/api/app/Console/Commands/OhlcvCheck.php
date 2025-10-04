<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Services\OhlcvIntegrityChecker;
use Illuminate\Console\Command;

class OhlcvCheck extends Command
{
    protected $signature = 'ohlcv:check
        {symbol? : Asset symbol to check}
        {--all : Check all configured index symbols}
        {--from= : Restrict the check to start at this YYYY-MM-DD date}
        {--to= : Restrict the check to end at this YYYY-MM-DD date}';

    protected $description = 'Check OHLCV bar integrity for assets.';

    public function __construct(private readonly OhlcvIntegrityChecker $checker)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;

        if ($this->option('all')) {
            return $this->checkAllConfiguredSymbols($from, $to);
        }

        $symbol = $this->argument('symbol');
        if ($symbol === null) {
            $this->error('Please provide a symbol or use the --all option.');

            return self::INVALID;
        }

        $result = $this->checkSymbol(strtoupper((string) $symbol), $from, $to);
        if ($result === null) {
            return self::FAILURE;
        }

        return $result ? self::SUCCESS : self::FAILURE;
    }

    private function checkAllConfiguredSymbols(?string $from, ?string $to): int
    {
        $symbols = array_map('strtoupper', config('csv.index_symbols', []));
        if ($symbols === []) {
            $this->error('No index symbols configured in csv.index_symbols.');

            return self::FAILURE;
        }

        $hadIssues = false;
        foreach ($symbols as $symbol) {
            $result = $this->checkSymbol($symbol, $from, $to);
            if ($result !== true) {
                $hadIssues = true;
            }
        }

        return $hadIssues ? self::FAILURE : self::SUCCESS;
    }

    private function checkSymbol(string $symbol, ?string $from, ?string $to): ?bool
    {
        /** @var Asset|null $asset */
        $asset = Asset::query()->where('symbol', $symbol)->first();

        if ($asset === null) {
            $this->warn(sprintf('Asset not found for symbol: %s', $symbol));

            return null;
        }

        $report = $this->checker->checkAsset($asset, $from, $to);

        $this->displayReport($report);

        return (bool) ($report['is_consistent'] ?? false);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function displayReport(array $report): void
    {
        $this->newLine();
        $this->info(sprintf('Asset %s (#%d)', $report['symbol'] ?? 'N/A', $report['asset_id']));
        $this->line(sprintf('  Range: %s → %s', $report['range_start'] ?? '-', $report['range_end'] ?? '-'));
        $this->line(sprintf('  First/Last bar: %s / %s', $report['first_bar'] ?? '-', $report['last_bar'] ?? '-'));
        $this->line(sprintf('  Global coverage: %s → %s', $report['global_first_bar'] ?? '-', $report['global_last_bar'] ?? '-'));
        $this->line(sprintf(
            '  Rows: %d total (%d unique) vs %d trading days',
            $report['total_rows'] ?? 0,
            $report['unique_rows'] ?? 0,
            $report['expected_trading_days'] ?? 0
        ));
        $this->line(sprintf('  Trading days covered: %d', $report['actual_trading_days'] ?? 0));

        if (!empty($report['missing_trading_days'])) {
            $this->warn('  Missing trading days: ' . implode(', ', $report['missing_trading_days']));
        }

        if (!empty($report['extra_bars'])) {
            $this->warn('  Extra bars: ' . implode(', ', $report['extra_bars']));
        }

        if (!empty($report['duplicate_bars'])) {
            $this->warn('  Duplicate bars: ' . implode(', ', $report['duplicate_bars']));
        }

        $status = ($report['is_consistent'] ?? false) ? '<fg=green>PASSED</>' : '<fg=red>FAILED</>';
        $this->line(sprintf('  Consistency: %s', $status));
    }
}
