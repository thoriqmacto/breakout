<?php

namespace App\Console\Commands;

use App\Services\YahooTradingDays;
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

        $this->info(sprintf('Upserted %d trading day records.', $count));

        return self::SUCCESS;
    }
}
