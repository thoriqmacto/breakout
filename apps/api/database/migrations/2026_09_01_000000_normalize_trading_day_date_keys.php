<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Collapse trading-day rows that were stored under two spellings of one date.
 *
 * `trading_days.date` is the primary key. A write through the model stored
 * "2026-08-28 00:00:00" -- the `date` cast produces a Carbon, which Eloquent
 * serialises with the model datetime format -- while every query-builder write
 * stored "2026-08-28". On an engine that does not coerce the column to a real
 * DATE, those are two different keys for the same session: an upsert keyed on
 * the short form never conflicted with the long one, so a repair inserted a
 * second row instead of updating the first, and the older NULL then shadowed
 * the good close on any ordered read.
 *
 * The write path is fixed, but rows already written under both spellings have
 * to be merged. The merge keeps the known close: that is the invariant the
 * whole import now enforces, and applying anything else here would discard the
 * very values the fix exists to preserve.
 *
 * Idempotent, and a no-op wherever the column really is a DATE -- MariaDB has
 * been coercing both spellings to the same key all along, so there is nothing
 * to collapse there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('trading_days')->orderBy('date')->get(['date', 'close']);

        /** @var array<string, array{close: float|null, spellings: array<int, string>}> $byDate */
        $byDate = [];

        foreach ($rows as $row) {
            $raw = (string) $row->date;

            try {
                $date = Carbon::parse($raw)->toDateString();
            } catch (Throwable) {
                continue;
            }

            $close = $row->close === null ? null : (float) $row->close;

            if (! isset($byDate[$date])) {
                $byDate[$date] = ['close' => $close, 'spellings' => [$raw]];

                continue;
            }

            $byDate[$date]['spellings'][] = $raw;

            // Known beats unknown, whichever spelling happened to carry it.
            if ($byDate[$date]['close'] === null) {
                $byDate[$date]['close'] = $close;
            }
        }

        foreach ($byDate as $date => $merged) {
            $spellings = array_values(array_unique($merged['spellings']));
            $needsRewrite = $spellings !== [$date];

            if (! $needsRewrite) {
                continue;
            }

            DB::table('trading_days')->whereIn('date', $spellings)->delete();

            DB::table('trading_days')->insert([
                'date' => $date,
                'close' => $merged['close'],
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    public function down(): void
    {
        // Merging duplicate spellings of one calendar date is not meaningfully
        // reversible, and re-splitting them would recreate the defect.
    }
};
