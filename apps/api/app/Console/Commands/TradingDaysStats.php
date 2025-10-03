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

        $rows = TradingDay::query()
            ->selectRaw('YEAR(date) as year, MONTH(date) as month, COUNT(*) as trading_days')
            ->when($year, static fn ($query) => $query->whereYear('date', (int) $year))
            ->groupByRaw('YEAR(date), MONTH(date)')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No trading day data available.');

            return self::SUCCESS;
        }

        $this->table(
            ['Year', 'Month', 'Trading Days'],
            $rows->map(fn ($row) => [
                $row->year,
                str_pad((string) $row->month, 2, '0', STR_PAD_LEFT),
                $row->trading_days,
            ])->toArray()
        );

        return self::SUCCESS;
    }
}
