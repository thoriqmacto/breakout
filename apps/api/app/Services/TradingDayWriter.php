<?php

namespace App\Services;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only thing that writes `trading_days`.
 *
 * It exists to make one rule impossible to bypass:
 *
 *     a known close is stronger information than an unknown one, and unknown
 *     must never overwrite known.
 *
 * `close = NULL` means "we do not know what the IHSG closed at that day". It
 * does not mean zero, and it does not mean the market was shut -- the row's
 * existence is what records that the session happened. So a payload that
 * carries the date but not the number may establish the session and must
 * leave any stored value alone, while a payload that carries a number always
 * wins, including over a stored NULL. That second half is the repair path: an
 * incomplete row heals itself the next time the provider supplies the figure,
 * with no manual SQL.
 *
 * Enforced by splitting the write rather than by trusting the caller. Rows
 * carrying a close are upserted and update the column; rows carrying none are
 * inserted-or-ignored and cannot touch it. No database-specific SQL, so the
 * guarantee is the same on SQLite and MariaDB.
 *
 * Dates are normalised to `Y-m-d` on the way in. The primary key is the date
 * itself, and a model write used to store `2026-08-28 00:00:00` where a query
 * builder write stored `2026-08-28`: two different keys for one session, so
 * the conflict never fired and the "update" quietly inserted a second row
 * whose NULL then shadowed the good one on every ordered read.
 */
class TradingDayWriter
{
    private const CHUNK_SIZE = 1000;

    /**
     * Persist trading days without ever downgrading a known close.
     *
     * @param  iterable<int, array{date: mixed, close?: mixed}>  $records
     * @return array{
     *     dates: array<int, string>,
     *     with_close: array<int, string>,
     *     date_only: array<int, string>,
     *     repaired: array<int, string>,
     *     preserved: array<int, string>,
     *     written: int,
     * }
     */
    public function write(iterable $records): array
    {
        $normalized = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $date = $this->normalizeDate($record['date'] ?? null);

            if ($date === null) {
                continue;
            }

            $close = $this->normalizeClose($record['close'] ?? null);

            // Last writer wins within one batch, but only upwards: a duplicate
            // date carrying no close cannot erase the one that did.
            if (array_key_exists($date, $normalized) && $close === null) {
                continue;
            }

            $normalized[$date] = $close;
        }

        if ($normalized === []) {
            return [
                'dates' => [],
                'with_close' => [],
                'date_only' => [],
                'repaired' => [],
                'preserved' => [],
                'written' => 0,
            ];
        }

        ksort($normalized);

        $existing = $this->existingCloses(array_keys($normalized));
        $now = Carbon::now();

        $withClose = [];
        $dateOnly = [];
        $repaired = [];
        $preserved = [];

        foreach ($normalized as $date => $close) {
            if ($close === null) {
                $dateOnly[] = $date;

                // Something already known that this payload could not confirm.
                if (($existing[$date] ?? null) !== null) {
                    $preserved[] = $date;
                }

                continue;
            }

            $withClose[] = $date;

            if (array_key_exists($date, $existing) && $existing[$date] === null) {
                $repaired[] = $date;
            }
        }

        $this->upsertWithCloses($withClose, $normalized, $now);
        $this->insertDatesOnly($dateOnly, $now);

        return [
            'dates' => array_keys($normalized),
            'with_close' => $withClose,
            'date_only' => $dateOnly,
            'repaired' => $repaired,
            'preserved' => $preserved,
            'written' => count($normalized),
        ];
    }

    /**
     * Stored closes for the given dates, keyed by date.
     *
     * Absent from the array means no row; present and null means a row whose
     * close is unknown. The two are different and the caller distinguishes
     * them, so this must not collapse them into one.
     *
     * @param  array<int, string>  $dates
     * @return array<string, float|null>
     */
    public function existingCloses(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $out = [];

        foreach (array_chunk($dates, self::CHUNK_SIZE) as $chunk) {
            $rows = DB::table('trading_days')
                ->whereIn('date', $chunk)
                ->get(['date', 'close']);

            foreach ($rows as $row) {
                $date = $this->normalizeDate($row->date);

                if ($date === null) {
                    continue;
                }

                $out[$date] = $row->close === null ? null : (float) $row->close;
            }
        }

        return $out;
    }

    /**
     * Dates in the range whose session is recorded but whose close is not.
     *
     * @return array<int, string>
     */
    public function incompleteDates(string $from, string $to): array
    {
        return DB::table('trading_days')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->whereNull('close')
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($value): ?string => $this->normalizeDate($value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $dates
     * @param  array<string, float|null>  $closes
     */
    private function upsertWithCloses(array $dates, array $closes, Carbon $now): void
    {
        foreach (array_chunk($dates, self::CHUNK_SIZE) as $chunk) {
            $payload = array_map(static fn (string $date): array => [
                'date' => $date,
                'close' => $closes[$date],
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            DB::table('trading_days')->upsert($payload, ['date'], ['close', 'updated_at']);
        }
    }

    /**
     * Establish the session without touching its close.
     *
     * insertOrIgnore rather than upsert: there is no update clause to get
     * wrong, so no future edit here can reintroduce the downgrade.
     *
     * @param  array<int, string>  $dates
     */
    private function insertDatesOnly(array $dates, Carbon $now): void
    {
        foreach (array_chunk($dates, self::CHUNK_SIZE) as $chunk) {
            $payload = array_map(static fn (string $date): array => [
                'date' => $date,
                'close' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            DB::table('trading_days')->insertOrIgnore($payload);
        }
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (InvalidFormatException) {
            return null;
        }
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
