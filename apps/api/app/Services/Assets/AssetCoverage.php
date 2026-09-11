<?php

namespace App\Services\Assets;

use App\Models\TradingDay;
use Illuminate\Support\Facades\DB;

/**
 * How complete each asset's price history actually is.
 *
 * The Assets page used to say how many bars an asset had and stop there, and
 * the Trading Days page knew which sessions the market held but nothing about
 * any one asset. Neither could answer the question an operator actually has --
 * "is this symbol missing days?" -- so it was answered by eye, across two
 * pages, one symbol at a time.
 *
 * A count of bars is not coverage. Fifty bars is complete for an asset listed
 * ten weeks ago and a hole for one listed two years ago, and the difference is
 * the trading calendar. So the sessions are counted from `trading_days`
 * between the asset's own first and last bar: the window is per asset, because
 * an asset cannot be missing sessions that happened before it existed.
 *
 * Everything is two queries regardless of how many assets are asked about. The
 * page renders fifty-odd rows and a per-asset query each would be fifty-odd
 * round trips for a column nobody would wait for.
 */
class AssetCoverage
{
    /**
     * Coverage for every asset that has at least one bar.
     *
     * Assets with no bars are absent from the result rather than present with
     * zeroes: "no data yet" and "data with holes" are different states, and
     * the caller decides how to show the first.
     *
     * @return array<int, array{
     *     bars: int,
     *     first_bar_date: ?string,
     *     last_bar_date: ?string,
     *     sessions_expected: int,
     *     sessions_missing: int,
     *     sessions_behind: int,
     *     complete: bool
     * }>
     */
    public function all(): array
    {
        $spans = DB::table('price_bars')
            ->select('asset_id', DB::raw('COUNT(*) as bar_count'), DB::raw('MIN(date) as first_date'), DB::raw('MAX(date) as last_date'))
            ->groupBy('asset_id')
            ->get();

        if ($spans->isEmpty()) {
            return [];
        }

        $sessions = $this->sessions();
        $latestSession = $sessions === [] ? null : $sessions[count($sessions) - 1];

        $coverage = [];

        foreach ($spans as $span) {
            $first = $this->dateOf($span->first_date);
            $last = $this->dateOf($span->last_date);
            $bars = (int) $span->bar_count;

            $expected = $this->sessionsBetween($sessions, $first, $last);

            // Never negative: an asset can hold a bar for a day the calendar
            // does not list -- a session the calendar has not caught up to, or
            // a date the exchange later corrected -- and reporting that as
            // "-2 missing" would read as a bug in the page rather than in the
            // calendar.
            $missing = max(0, $expected - $bars);

            $coverage[(int) $span->asset_id] = [
                'bars' => $bars,
                'first_bar_date' => $first,
                'last_bar_date' => $last,
                'sessions_expected' => $expected,
                'sessions_missing' => $missing,
                'sessions_behind' => $this->sessionsAfter($sessions, $last, $latestSession),
                'complete' => $missing === 0,
            ];
        }

        return $coverage;
    }

    /**
     * Coverage for one asset, in the same shape, or null when it has no bars.
     *
     * @return array<string, mixed>|null
     */
    public function forAsset(int $assetId): ?array
    {
        return $this->all()[$assetId] ?? null;
    }

    /**
     * Every session the market is known to have held, ascending.
     *
     * Read once and reused for every asset. Only dates with a close count: a
     * row whose close is unknown is a session the calendar has not confirmed,
     * and counting it as expected would invent a gap in every asset at once.
     *
     * @return array<int, string>
     */
    private function sessions(): array
    {
        return TradingDay::query()
            ->whereNotNull('close')
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date): string => $date instanceof \DateTimeInterface
                ? $date->format('Y-m-d')
                : (string) $date)
            ->all();
    }

    /**
     * @param  array<int, string>  $sessions
     */
    private function sessionsBetween(array $sessions, ?string $from, ?string $to): int
    {
        if ($from === null || $to === null) {
            return 0;
        }

        $count = 0;

        foreach ($sessions as $session) {
            if ($session >= $from && $session <= $to) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Sessions the market held after this asset's last bar.
     *
     * This is the number that says "stale", and it is deliberately separate
     * from `sessions_missing`: a hole in the middle of a history and a symbol
     * that stopped updating a week ago are different failures with different
     * fixes, and one number covering both would name neither.
     *
     * @param  array<int, string>  $sessions
     */
    private function sessionsAfter(array $sessions, ?string $last, ?string $latestSession): int
    {
        if ($last === null || $latestSession === null || $last >= $latestSession) {
            return 0;
        }

        $count = 0;

        foreach ($sessions as $session) {
            if ($session > $last) {
                $count++;
            }
        }

        return $count;
    }

    private function dateOf(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $text = trim((string) $value);

        return $text === '' ? null : substr($text, 0, 10);
    }
}
