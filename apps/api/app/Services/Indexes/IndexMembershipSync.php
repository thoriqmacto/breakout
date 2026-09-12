<?php

namespace App\Services\Indexes;

use App\Models\IndexMembership;
use App\Models\MarketIndex;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turn a list of symbols into a dated membership.
 *
 * Every source -- the scheduled browser read, a pasted list, a file -- ends
 * here, so there is one implementation of what "joined" and "left" mean and
 * one set of rules about when a list is too suspect to write.
 *
 * Those rules exist because the failure mode is silent. A catalogue page that
 * renders thirty of its seventy rows before the read gives up produces a
 * perfectly well-formed list of thirty symbols; accepting it would strip the
 * badge from forty stocks and record forty departures that never happened, and
 * the next morning's sync would record forty joins to match. So a list has to
 * clear a floor, and may not drop more than a configured share of the current
 * membership, before anything is written. `force` is the deliberate override
 * for the day the exchange really does rebuild the index.
 *
 * Nothing is ever deleted. A departure is a date on the row it already had.
 */
class IndexMembershipSync
{
    public function __construct(private readonly IndexRegistry $registry) {}

    /**
     * @param  array<int, mixed>  $rawSymbols
     * @param  string  $source  'browser', 'manual', 'file' -- recorded, not interpreted
     * @param  bool  $dryRun  compute and report the change without writing it
     */
    public function apply(
        string $code,
        array $rawSymbols,
        string $source,
        ?Carbon $asOf = null,
        bool $force = false,
        bool $dryRun = false,
    ): IndexSyncResult {
        $code = $this->registry->normaliseCode($code);
        $definition = $this->registry->require($code);

        $effectiveOn = ($asOf ?? Carbon::now(config('automation.timezone', 'Asia/Jakarta')))->toDateString();

        ['symbols' => $symbols, 'rejected' => $rejected] = $this->registry->filterSymbols($rawSymbols);

        $currentSymbols = $this->currentSymbols($code);
        $previousCount = count($currentSymbols);

        $refusal = $this->refusalReason($symbols, $currentSymbols, $definition, $force);

        if ($refusal !== null) {
            return IndexSyncResult::refused(
                $code,
                $source,
                $effectiveOn,
                $refusal,
                count($symbols),
                $previousCount,
                $rejected,
            );
        }

        $incoming = array_flip($symbols);
        $existing = array_flip($currentSymbols);

        $joined = array_values(array_diff($symbols, $currentSymbols));
        $removed = array_values(array_diff($currentSymbols, $symbols));
        $unchanged = count(array_intersect_key($incoming, $existing));

        if (! $dryRun) {
            $this->write($code, $symbols, $removed, $effectiveOn, $source, $definition);
        }

        return IndexSyncResult::applied(
            $code,
            $source,
            $effectiveOn,
            count($symbols),
            $previousCount,
            count($symbols),
            $joined,
            $removed,
            $unchanged,
            $rejected,
        );
    }

    /**
     * @param  array<int, string>  $symbols
     * @param  array<int, string>  $removed
     * @param  array<string, mixed>  $definition
     */
    private function write(
        string $code,
        array $symbols,
        array $removed,
        string $effectiveOn,
        string $source,
        array $definition,
    ): void {
        DB::transaction(function () use ($code, $symbols, $removed, $effectiveOn, $source, $definition) {
            $now = Carbon::now();

            foreach ($symbols as $symbol) {
                $membership = IndexMembership::query()
                    ->where('index_code', $code)
                    ->where('symbol', $symbol)
                    ->first();

                if ($membership === null) {
                    IndexMembership::query()->create([
                        'index_code' => $code,
                        'symbol' => $symbol,
                        'joined_on' => $effectiveOn,
                        'last_seen_on' => $effectiveOn,
                        'removed_on' => null,
                    ]);

                    continue;
                }

                // A symbol that comes back starts a new spell. Keeping the
                // original joined_on would claim it never left, which is the
                // one thing the row exists to contradict.
                if ($membership->removed_on !== null) {
                    $membership->joined_on = $effectiveOn;
                    $membership->removed_on = null;
                }

                $membership->last_seen_on = $effectiveOn;
                $membership->save();
            }

            if ($removed !== []) {
                IndexMembership::query()
                    ->where('index_code', $code)
                    ->whereIn('symbol', $removed)
                    ->whereNull('removed_on')
                    ->update(['removed_on' => $effectiveOn, 'updated_at' => $now]);
            }

            MarketIndex::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'] ?? $code,
                    'source_url' => $definition['url'] ?? null,
                    'last_source' => $source,
                    'last_synced_at' => $now,
                    'last_effective_on' => $effectiveOn,
                    'member_count' => count($symbols),
                ],
            );
        });
    }

    /**
     * Symbols currently in the index, uppercased and sorted.
     *
     * @return array<int, string>
     */
    public function currentSymbols(string $code): array
    {
        return IndexMembership::query()
            ->where('index_code', $this->registry->normaliseCode($code))
            ->current()
            ->orderBy('symbol')
            ->pluck('symbol')
            ->map(static fn ($symbol): string => strtoupper((string) $symbol))
            ->all();
    }

    /**
     * Index codes each symbol currently belongs to, keyed by symbol.
     *
     * One query for the whole table: the Assets page renders a badge per row,
     * and a lookup per row would be a query per symbol for a chip.
     *
     * @param  array<int, string>|null  $symbols  null for every symbol held
     * @return array<string, array<int, string>>
     */
    public function currentBySymbol(?array $symbols = null): array
    {
        $query = IndexMembership::query()->current();

        if ($symbols !== null) {
            if ($symbols === []) {
                return [];
            }

            $query->whereIn('symbol', array_map(
                static fn ($symbol): string => strtoupper(trim((string) $symbol)),
                $symbols,
            ));
        }

        $map = [];

        foreach ($query->orderBy('index_code')->get(['symbol', 'index_code']) as $row) {
            $symbol = strtoupper((string) $row->symbol);
            $map[$symbol][] = (string) $row->index_code;
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $symbols
     * @param  array<int, string>  $current
     * @param  array<string, mixed>  $definition
     */
    private function refusalReason(array $symbols, array $current, array $definition, bool $force): ?string
    {
        if ($symbols === []) {
            // Never overridable. "The index is empty" is not a state a real
            // index reaches, so force would only ever mean "wipe it".
            return 'the list contained no usable symbols';
        }

        if ($force) {
            return null;
        }

        $minimum = (int) ($definition['min_members'] ?? 0);

        if ($minimum > 0 && count($symbols) < $minimum) {
            return sprintf(
                'only %d symbols were read and at least %d are expected, which reads as a partial page rather than a shrunken index (--force overrides)',
                count($symbols),
                $minimum,
            );
        }

        if ($current === []) {
            return null;
        }

        $dropped = count(array_diff($current, $symbols));
        $ratio = (float) config('market_indexes.max_shrink_ratio', 0.25);
        $allowed = (int) floor(count($current) * $ratio);

        if ($dropped > $allowed) {
            return sprintf(
                'it would drop %d of %d current members and at most %d may go in one sync (--force overrides)',
                $dropped,
                count($current),
                $allowed,
            );
        }

        return null;
    }
}
