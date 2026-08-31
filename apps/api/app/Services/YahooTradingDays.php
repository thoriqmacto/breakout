<?php

namespace App\Services;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Imports the IHSG session calendar and its closing values from Yahoo.
 *
 * Two ranges, deliberately not the same one:
 *
 *   the persistence range   the sessions the caller asked to store
 *   the fetch range         what the provider is asked to download
 *
 * The fetch range reaches further back, because a download window that begins
 * exactly on the session you need has been observed to come back without it.
 * That is how a repair silently does nothing -- the row stays NULL because the
 * date was never in the response. Records outside the persistence range are
 * discarded before any write, so the buffer changes what is fetched and never
 * what is stored.
 *
 * Nothing here decides how a close is written. That is TradingDayWriter's
 * single rule -- a known close is never overwritten by an unknown one -- and
 * routing every write through it is what keeps a payload that carries the date
 * but not the number from erasing a value the ledger already had.
 */
class YahooTradingDays
{
    private const SYMBOL = '^JKSE';

    private const SCRIPT = 'get_stocks.py';

    public function __construct(
        private readonly PythonRunner $pythonRunner,
        private readonly TradingDayWriter $writer,
    ) {}

    public function import(string $from = '2015-01-01', ?string $to = null): TradingDayImportReport
    {
        $startDate = Carbon::parse($from)->startOfDay();
        $endDate = $to ? Carbon::parse($to)->endOfDay() : Carbon::now();

        if ($endDate->lessThan($startDate)) {
            throw new InvalidArgumentException('The end date must be greater than or equal to the start date.');
        }

        $buffer = max(0, (int) config('trading_days.fetch_buffer_days', 7));
        $fetchFrom = $startDate->copy()->subDays($buffer);

        $result = $this->pythonRunner->run(self::SCRIPT, null, [
            self::SYMBOL,
            sprintf('--start=%s', $fetchFrom->toDateString()),
            sprintf('--end=%s', $endDate->copy()->toDateString()),
            '--emit-dates',
        ]);

        if (! $result['ok']) {
            $errorMessage = trim($result['stderr'] ?: $result['stdout']);
            throw new RuntimeException($errorMessage !== '' ? $errorMessage : 'Failed to fetch trading days from Python script.');
        }

        $empty = new TradingDayImportReport(
            from: $startDate->toDateString(),
            to: $endDate->toDateString(),
            fetchedFrom: $fetchFrom->toDateString(),
        );

        $payload = $result['json'];

        if (! is_array($payload)) {
            return $empty;
        }

        $tickerData = Collection::make($payload['tickers'] ?? [])
            ->first(fn (mixed $item): bool => is_array($item) && ($item['ticker'] ?? null) === self::SYMBOL);

        if (! is_array($tickerData)) {
            return $empty;
        }

        $providerCloses = $this->providerCloses($tickerData, $startDate, $endDate);

        if ($providerCloses === []) {
            return $empty;
        }

        $write = $this->writer->write(array_map(
            static fn (string $date, ?float $close): array => ['date' => $date, 'close' => $close],
            array_keys($providerCloses),
            array_values($providerCloses),
        ));

        return new TradingDayImportReport(
            from: $startDate->toDateString(),
            to: $endDate->toDateString(),
            fetchedFrom: $fetchFrom->toDateString(),
            providerCloses: $providerCloses,
            repaired: $write['repaired'],
            preserved: $write['preserved'],
        );
    }

    /**
     * What the provider said about each session inside the persistence range.
     *
     * `entries` wins whenever it carries anything. The payload contains both
     * `dates` and `entries`, and the two answer different questions: `dates`
     * only says a session happened, `entries` says what it closed at. Choosing
     * the legacy list merely because its key is also present throws away every
     * close in the response -- which, before the writer's rule existed, then
     * wrote NULL over all of them.
     *
     * @param  array<string, mixed>  $tickerData
     * @return array<string, float|null> date => close, ascending
     */
    private function providerCloses(array $tickerData, Carbon $startDate, Carbon $endDate): array
    {
        $entries = $tickerData['entries'] ?? null;
        $out = [];

        if (is_array($entries) && $entries !== []) {
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $date = $this->dateInRange($entry['date'] ?? null, $startDate, $endDate);

                if ($date === null) {
                    continue;
                }

                $out[$date] = $this->normalizeClose($entry['close'] ?? null);
            }

            ksort($out);

            return $out;
        }

        // Legacy shape: sessions without values. It can establish that a day
        // traded; it can never say anything about a close, so every date maps
        // to null and the writer leaves stored values alone.
        foreach ((array) ($tickerData['dates'] ?? []) as $value) {
            $date = $this->dateInRange($value, $startDate, $endDate);

            if ($date !== null) {
                $out[$date] = null;
            }
        }

        ksort($out);

        return $out;
    }

    /**
     * A provider date, normalised, or null when it is unusable or lies outside
     * the range being persisted.
     *
     * This is the gate the fetch buffer depends on: the provider is asked for
     * more than the caller wanted, and everything earlier is dropped here.
     */
    private function dateInRange(mixed $value, Carbon $startDate, Carbon $endDate): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', trim($value))->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }

        if ($parsed->lessThan($startDate->copy()->startOfDay()) || $parsed->greaterThan($endDate)) {
            return null;
        }

        return $parsed->toDateString();
    }

    private function normalizeClose(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }
        }

        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : null;
    }
}
