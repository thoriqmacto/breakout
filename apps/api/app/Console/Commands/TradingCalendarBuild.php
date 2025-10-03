<?php

namespace App\Console\Commands;

use App\Services\TradingCalendarBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

class TradingCalendarBuild extends Command
{
    protected $signature = 'trading-calendar:build {--from=2015-01-01} {--to=} {--holiday-file=}';

    protected $aliases = ['calendar:build'];

    protected $description = 'Build or refresh the trading calendar table.';

    public function __construct(private readonly TradingCalendarBuilder $builder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = $this->option('to') ?: Carbon::today()->toDateString();
        $holidayFile = $this->option('holiday-file');

        try {
            $holidayReasons = $this->builder->loadHolidayReasons($holidayFile);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $count = $this->builder->build($from, $to, $holidayReasons);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Upserted %d calendar rows from %s to %s.', $count, $from, $to));
        $this->info(sprintf('Holiday reasons applied: %d', count($holidayReasons)));

        return self::SUCCESS;
    }
}
