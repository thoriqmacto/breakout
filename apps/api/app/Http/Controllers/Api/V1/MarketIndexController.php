<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Jobs\BackfillAssetHistoryJob;
use App\Models\Asset;
use App\Models\IndexMembership;
use App\Models\MarketIndex;
use App\Services\Assets\AssetCoverage;
use App\Services\Indexes\IndexMembershipSync;
use App\Services\Indexes\IndexRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * What a published index holds, and how much of it this installation follows.
 *
 * The interesting half is the gap. A member this installation does not track
 * has no asset row, no bars and no broker summary, so it cannot appear on any
 * other page -- which is precisely why the index panel is where new candidates
 * come from, and why `track` exists.
 *
 * `track` only ever accepts symbols that are current members of the named
 * index. The browser sends a list of tickers; the server does not take that as
 * permission to create arbitrary assets, it checks each one against the
 * membership it stored itself.
 */
class MarketIndexController extends ApiController
{
    /**
     * How many days of departures the panel shows.
     *
     * Long enough to cover a review that happened while nobody was looking,
     * short enough that the list does not become a graveyard.
     */
    private const RECENT_DAYS = 90;

    public function __construct(
        private readonly IndexRegistry $registry,
        private readonly IndexMembershipSync $sync,
    ) {}

    /**
     * Every configured index, with the little that fits on a card.
     */
    public function index()
    {
        $stored = MarketIndex::query()->get()->keyBy('code');
        $tracked = $this->trackedSymbols();

        $indexes = [];

        foreach ($this->registry->all() as $code => $definition) {
            $members = $this->sync->currentSymbols($code);

            $indexes[] = [
                'code' => $code,
                'name' => $definition['name'] ?? $code,
                'description' => $definition['description'] ?? null,
                'source_url' => $definition['url'] ?? null,
                'expected_size' => $definition['expected_size'] ?? null,
                'member_count' => count($members),
                'tracked_count' => count(array_intersect($members, $tracked)),
                'last_synced_at' => $stored[$code]?->last_synced_at?->toIso8601String(),
                // A day, spelled as one: the memberships store plain dates and a
                // datetime here would be the same fact in a second format.
                'last_effective_on' => $stored[$code]?->last_effective_on?->toDateString(),
                'last_source' => $stored[$code]?->last_source,
            ];
        }

        return ApiResponse::success([
            'default' => $this->registry->defaultCode(),
            'indexes' => $indexes,
        ]);
    }

    /**
     * One index: every current member, plus what recently left.
     */
    public function show(string $code, AssetCoverage $coverage)
    {
        $code = $this->registry->normaliseCode($code);

        if (! $this->registry->has($code)) {
            return ApiResponse::error('Unknown index.', 404);
        }

        $definition = $this->registry->require($code);
        $stored = MarketIndex::query()->where('code', $code)->first();

        $memberships = IndexMembership::query()
            ->where('index_code', $code)
            ->orderBy('symbol')
            ->get();

        $assets = Asset::query()
            ->whereIn('symbol', $memberships->pluck('symbol')->all())
            ->get()
            ->keyBy(static fn (Asset $asset): string => strtoupper((string) $asset->symbol));

        $coverageByAsset = $coverage->all();
        $cutoff = Carbon::now()->subDays(self::RECENT_DAYS)->toDateString();

        $members = [];
        $departed = [];

        foreach ($memberships as $membership) {
            $symbol = strtoupper((string) $membership->symbol);
            $asset = $assets->get($symbol);
            $assetCoverage = $asset === null ? null : ($coverageByAsset[(int) $asset->id] ?? null);

            $row = [
                'symbol' => $symbol,
                'name' => $asset?->name,
                'joined_on' => $membership->joined_on,
                'last_seen_on' => $membership->last_seen_on,
                'removed_on' => $membership->removed_on,
                'tracked' => $asset !== null,
                'asset_id' => $asset?->id,
                'sync_price' => $asset === null ? null : (bool) $asset->sync_price,
                'sync_broker_summary' => $asset === null ? null : (bool) $asset->sync_broker_summary,
                // Null rather than 0 for an untracked symbol: nobody has tried
                // to collect it, which is not the same as having tried and got
                // nothing.
                'bars' => $assetCoverage['bars'] ?? null,
                'last_bar_date' => $assetCoverage['last_bar_date'] ?? null,
            ];

            if ($membership->removed_on === null) {
                $members[] = $row;

                continue;
            }

            if ((string) $membership->removed_on >= $cutoff) {
                $departed[] = $row;
            }
        }

        return ApiResponse::success([
            'index' => [
                'code' => $code,
                'name' => $definition['name'] ?? $code,
                'description' => $definition['description'] ?? null,
                'source_url' => $definition['url'] ?? null,
                'expected_size' => $definition['expected_size'] ?? null,
                'member_count' => count($members),
                'tracked_count' => count(array_filter($members, static fn (array $row): bool => $row['tracked'])),
                'last_synced_at' => $stored?->last_synced_at?->toIso8601String(),
                'last_effective_on' => $stored?->last_effective_on?->toDateString(),
                'last_source' => $stored?->last_source,
                'recent_days' => self::RECENT_DAYS,
            ],
            'members' => $members,
            'departed' => $departed,
        ]);
    }

    /**
     * Replace the membership from a list a person pasted in.
     *
     * The same path the scheduled read takes, guards included, so a pasted
     * list cannot do anything the scraped one could not. `force` is accepted
     * because a person looking at the real page is exactly who can tell a
     * genuine rebuild from a half-loaded one.
     */
    public function storeMembers(Request $request, string $code)
    {
        $code = $this->registry->normaliseCode($code);

        if (! $this->registry->has($code)) {
            return ApiResponse::error('Unknown index.', 404);
        }

        $payload = $request->validate([
            'symbols' => ['required', 'array', 'min:1', 'max:500'],
            'symbols.*' => ['required', 'string', 'max:16'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $result = $this->sync->apply(
            $code,
            $payload['symbols'],
            'manual',
            null,
            $request->boolean('force'),
        );

        if (! $result->accepted) {
            return ApiResponse::error($result->summary(), 422, $result->toArray());
        }

        return ApiResponse::success($result->toArray(), $result->summary());
    }

    /**
     * Start collecting data for index members this installation does not hold.
     *
     * Creating the asset row is the whole mechanism for the daily collection:
     * the OHLCV job reads every asset with sync_price set and the
     * broker-summary job every asset with sync_broker_summary set, so an asset
     * created here joins tonight's run with no further wiring.
     *
     * History is a separate problem, because the daily job asks for exactly
     * one session. Left at that a new symbol would gain one bar an evening and
     * take a year to become usable -- ROC 13w needs 66 bars, the 55-week high
     * needs 275 -- so each newly created asset also gets a queued backfill
     * that walks its whole history from the IPO date, the same path that built
     * the assets this installation already holds.
     */
    public function track(Request $request, string $code)
    {
        $code = $this->registry->normaliseCode($code);

        if (! $this->registry->has($code)) {
            return ApiResponse::error('Unknown index.', 404);
        }

        $payload = $request->validate([
            'symbols' => ['required', 'array', 'min:1', 'max:200'],
            'symbols.*' => ['required', 'string', 'max:16'],
        ]);

        $requested = array_values(array_unique(array_map(
            static fn ($symbol): string => strtoupper(trim((string) $symbol)),
            $payload['symbols'],
        )));

        // The membership this server stored is the authority on what may be
        // created, not the list the browser sent.
        $members = array_flip($this->sync->currentSymbols($code));

        $created = [];
        $existing = [];
        $refused = [];

        foreach ($requested as $symbol) {
            if (! isset($members[$symbol])) {
                $refused[] = $symbol;

                continue;
            }

            $asset = Asset::query()->where('symbol', $symbol)->first();

            if ($asset !== null) {
                // An asset that exists but was switched off is switched back
                // on: "add to breakout" means "collect this", and silently
                // doing nothing for a symbol the user just selected would be
                // the worst of both.
                if (! $asset->sync_price || ! $asset->sync_broker_summary) {
                    $asset->sync_price = true;
                    $asset->sync_broker_summary = true;
                    $asset->save();
                }

                $existing[] = $symbol;

                continue;
            }

            Asset::query()->create([
                'symbol' => $symbol,
                // The backfill syncs the profile on its way to the IPO date
                // and replaces this with the listed name.
                'name' => $symbol,
                'sync_price' => true,
                'sync_profile' => true,
                'sync_broker_summary' => true,
            ]);

            // Queued, not awaited: a full history is minutes of API calls per
            // symbol, and it takes the shared Stockbit lock so it waits its
            // turn behind the evening collectors rather than competing.
            BackfillAssetHistoryJob::dispatch($symbol);

            $created[] = $symbol;
        }

        $message = $created === []
            ? 'Nothing new to add.'
            : sprintf(
                '%s added. Daily collection starts at the next scheduled run, and a full history backfill from '
                .'each symbol\'s IPO date is queued now -- it runs in the background and waits for the evening '
                .'collectors if they are working.',
                implode(', ', $created),
            );

        return ApiResponse::success([
            'created' => $created,
            'already_tracked' => $existing,
            'refused' => $refused,
        ], $message);
    }

    /**
     * @return array<int, string>
     */
    private function trackedSymbols(): array
    {
        return Asset::query()
            ->pluck('symbol')
            ->map(static fn ($symbol): string => strtoupper((string) $symbol))
            ->all();
    }
}
