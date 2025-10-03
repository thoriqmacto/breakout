<?php

namespace App\Console\Commands;

use App\Models\TradingDay;
use Illuminate\Console\Command;

class TradingDaysStats extends Command
{
    protected $signature = 'trading-days:stats {--year=}';

    protected $description = 'Display trading day counts grouped by year and month.';

    public function handle(): int
    {
        $year = $this->option('year');

        $query = TradingDay::query()
            ->when($year, static fn ($query) => $query->whereYear('date', (int) $year));

        $connectionDriver = $query->getConnection()->getDriverName();

        $yearExpression = match ($connectionDriver) {
            'sqlite' => "strftime('%Y', date)",
            'pgsql' => 'EXTRACT(YEAR FROM date)',
            default => 'YEAR(date)',
        };

        $monthExpression = match ($connectionDriver) {
            'sqlite' => "strftime('%m', date)",
            'pgsql' => 'EXTRACT(MONTH FROM date)',
            default => 'MONTH(date)',
        };

        $rows = (clone $query)
            ->selectRaw("{$yearExpression} as year, {$monthExpression} as month, COUNT(*) as trading_days")
            ->groupByRaw("{$yearExpression}, {$monthExpression}")
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No trading day data available.');

            return self::SUCCESS;
        }

        $fromDate = (clone $query)->min('date');
        $toDate = (clone $query)->max('date');
        $totalTradingDays = (clone $query)->count();

        $this->info('Trading Day Statistics');
        $this->line(sprintf(
            'Total trading days: %d (%s to %s)',
            $totalTradingDays,
            $fromDate,
            $toDate
        ));

        $this->table(
            ['Year', 'Month', 'Days'],
            $rows->map(fn ($row) => [
                $row->year,
                str_pad((string) $row->month, 2, '0', STR_PAD_LEFT),
                $row->trading_days,
            ])->toArray()
        );

        return self::SUCCESS;
    }
}
