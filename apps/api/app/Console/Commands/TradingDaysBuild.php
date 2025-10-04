<?php

namespace App\Console\Commands;

use App\Models\TradingDay;
use App\Services\YahooTradingDays;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;

class TradingDaysBuild extends Command
{
    protected $signature = 'trading-days:build {--from=2015-01-01} {--to=}';

    protected $description = 'Populate the trading_days table using Yahoo Finance historical data.';

    public function __construct(private readonly YahooTradingDays $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = $this->option('to');

        $count = $this->service->import($from, $to ?: null);

        $fromDate = Carbon::parse($from)->toDateString();
        $toDate = Carbon::parse($to ?: Carbon::now()->toDateString())->toDateString();

        $withClose = TradingDay::query()
            ->whereBetween('date', [$fromDate, $toDate])
            ->whereNotNull('close')
            ->count();

        $this->info(sprintf(
            'Upserted %d trading day records (%d with a close value).',
            $count,
            $withClose
        ));

        return self::SUCCESS;
    }
}
