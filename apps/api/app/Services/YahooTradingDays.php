<?php

namespace App\Services;

use App\Models\TradingDay;
use Carbon\Exceptions\InvalidFormatException;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class YahooTradingDays
{
    private const SYMBOL = '^JKSE';
    private const BASE_URL = 'https://query1.finance.yahoo.com/v7/finance/download/';
    private const CHUNK_SIZE = 1000;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly TradingDay $tradingDay,
    ) {
    }

    public function import(string $from = '2015-01-01', ?string $to = null): int
    {
        $startDate = Carbon::parse($from)->startOfDay();
        $endDate = $to ? Carbon::parse($to)->endOfDay() : Carbon::now();

        if ($endDate->lessThan($startDate)) {
            throw new InvalidArgumentException('The end date must be greater than or equal to the start date.');
        }

        $period1 = $startDate->timestamp;
        $period2 = $endDate->copy()->addDay()->startOfDay()->timestamp;

        $url = sprintf(
            '%s%s?period1=%d&period2=%d&interval=1d&events=history&includeAdjustedClose=true',
            self::BASE_URL,
            rawurlencode(self::SYMBOL),
            $period1,
            $period2,
        );

        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Accept' => 'text/csv',
            ],
        ]);

        $body = (string) $response->getBody();
        $lines = preg_split("/\r\n|\r|\n/", trim($body));

        if ($lines === false || count($lines) <= 1) {
            return 0;
        }

        array_shift($lines); // remove header

        $now = Carbon::now();

        $records = Collection::make($lines)
            ->filter()
            ->map(function (string $line) use ($now) {
                $columns = str_getcsv($line);
                $date = $columns[0] ?? null;

                if ($date === null) {
                    return null;
                }

                try {
                    Carbon::createFromFormat('Y-m-d', $date);
                } catch (InvalidFormatException) {
                    return null;
                }

                return [
                    'date' => $date,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->filter()
            ->unique('date')
            ->values();

        if ($records->isEmpty()) {
            return 0;
        }

        $records
            ->chunk(self::CHUNK_SIZE)
            ->each(function (Collection $chunk): void {
                $this->tradingDay->newQuery()->upsert(
                    $chunk->all(),
                    ['date'],
                    ['updated_at']
                );
            });

        return $records->count();
    }
}
