<?php

namespace App\Services\Portfolio\Import;

use App\Models\Asset;
use App\Models\CashMovement;
use App\Models\Portfolio;
use App\Models\Position;
use App\Services\Portfolio\PortfolioCalculator;
use App\Services\Portfolio\PositionPricing;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns a pasted Stockbit response into portfolio ledger rows.
 *
 * The whole thing is built around one idea: analyse first, write second, and
 * never let the two disagree. `analyze()` does every lookup, calculation and
 * duplicate check without touching the database for writes, and `commit()`
 * runs that same analysis again server-side before persisting. The browser's
 * preview is therefore only ever a display of what the server decided -- it is
 * never an input to what gets written.
 *
 * Two payloads are handled, and they mean different things:
 *
 *   history  -- a ledger of real executions. Imported as positions and cash
 *               movements, keyed on the broker's own transaction id so the
 *               same paste can be repeated safely.
 *   snapshot -- the broker's current view. Treated as a reconciliation
 *               statement, not as data to import: it is compared against what
 *               the ledger already computes, and only creates anything when
 *               the user explicitly asks for the opening-position fallback.
 */
class StockbitPortfolioImporter
{
    public const STATUS_NEW = 'new';

    public const STATUS_DUPLICATE = 'skipped_duplicate';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ERROR = 'error';

    /**
     * Money is compared to the rupiah, or to a hundredth of a percent on large
     * figures -- whichever is looser. The broker publishes an average price
     * rounded to four decimals and multiplies it back out, so its "invested"
     * figure legitimately differs from the exact ledger sum by fractions of a
     * rupiah. Comparing floats exactly here would report a discrepancy on a
     * perfectly reconciled portfolio.
     */
    private const MONEY_ABS_TOLERANCE = 1.0;

    private const MONEY_REL_TOLERANCE = 0.0001;

    /** Share counts are whole numbers in practice; this absorbs float noise. */
    private const SHARE_TOLERANCE = 0.0001;

    /** Average cost is quoted to four decimals by the broker. */
    private const PRICE_TOLERANCE = 0.01;

    public function __construct(
        private readonly StockbitPayloadParser $parser,
        private readonly PositionPricing $pricing,
        private readonly PortfolioCalculator $calculator,
    ) {}

    /**
     * Inspect a payload and describe exactly what an import would do.
     *
     * Performs no writes.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException on malformed or unrecognised JSON
     */
    public function analyze(Portfolio $portfolio, string $raw, array $options = []): array
    {
        $payload = $this->parser->decode($raw);
        $type = $this->parser->detectType($payload);

        return $type === StockbitPayloadParser::TYPE_HISTORY
            ? $this->analyzeHistory($portfolio, $payload)
            : $this->analyzeSnapshot($portfolio, $payload, $options);
    }

    /**
     * Analyse, then persist inside a single transaction.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function commit(Portfolio $portfolio, string $raw, array $options = []): array
    {
        // Re-derived here rather than accepted from the request: a preview is
        // a picture, not a promise.
        $analysis = $this->analyze($portfolio, $raw, $options);

        if (! $analysis['can_commit']) {
            return $analysis + [
                'committed' => false,
                'created' => ['positions' => 0, 'cash_movements' => 0],
            ];
        }

        return DB::transaction(function () use ($portfolio, $analysis, $options): array {
            $createdPositions = [];
            $createdMovements = [];

            foreach ($analysis['trades'] as $trade) {
                if ($trade['import_status'] !== self::STATUS_NEW) {
                    continue;
                }

                $createdPositions[] = $portfolio->positions()->create($trade['payload'])->id;
            }

            foreach ($analysis['dividends'] as $dividend) {
                if ($dividend['import_status'] !== self::STATUS_NEW) {
                    continue;
                }

                $createdMovements[] = $portfolio->cashMovements()->create($dividend['payload'])->id;
            }

            foreach ($analysis['snapshot']['positions'] ?? [] as $row) {
                if (($row['import_status'] ?? null) !== self::STATUS_NEW) {
                    continue;
                }

                $createdPositions[] = $portfolio->positions()->create($row['payload'])->id;
            }

            $cashAdjustment = null;
            $cash = $analysis['snapshot']['cash'] ?? null;

            // Cash is only ever touched on an explicit opt-in, and the figure
            // written is a *base* cash balance: the calculator adds signed
            // movements on top, so writing the broker's current cash here
            // directly would count every imported dividend twice.
            if ($cash !== null && ($options['reconcile_cash'] ?? false) && $cash['can_reconcile']) {
                $portfolio->forceFill(['cash_balance' => $cash['proposed_base_cash']])->save();
                $cashAdjustment = $cash['proposed_base_cash'];
            }

            return $analysis + [
                'committed' => true,
                'created' => [
                    'positions' => count($createdPositions),
                    'cash_movements' => count($createdMovements),
                ],
                'created_position_ids' => $createdPositions,
                'created_cash_movement_ids' => $createdMovements,
                'cash_balance_set_to' => $cashAdjustment,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function analyzeHistory(Portfolio $portfolio, array $payload): array
    {
        $rows = $this->parser->parseHistory($payload);

        $assets = $this->resolveAssets(array_column($rows, 'symbol'));
        $existing = $this->existingExternalIds($portfolio, array_filter(array_column($rows, 'external_id')));

        $trades = [];
        $dividends = [];
        $skipped = [];
        $errors = [];
        $warnings = [];
        $missingAssets = [];

        // Two rows in the same paste can carry the same id -- Stockbit
        // occasionally repeats one across month buckets. The first wins and
        // the rest are duplicates, exactly as on a re-paste.
        $seen = [];

        foreach ($rows as $row) {
            $classified = $this->classifyHistoryRow($row, $portfolio, $assets, $existing, $seen, $missingAssets, $warnings);

            match (true) {
                $classified['bucket'] === 'dividend' => $dividends[] = $classified['row'],
                $classified['bucket'] === 'trade' => $trades[] = $classified['row'],
                $classified['bucket'] === 'error' => $errors[] = $classified['row'],
                default => $skipped[] = $classified['row'],
            };
        }

        $all = array_merge($trades, $dividends, $skipped, $errors);
        $totals = $this->totals($all);

        return [
            'type' => StockbitPayloadParser::TYPE_HISTORY,
            'portfolio_id' => (int) $portfolio->id,
            'trades' => $trades,
            'dividends' => $dividends,
            'skipped' => $skipped,
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'missing_assets' => array_values(array_unique($missingAssets)),
            'snapshot' => null,
            'totals' => $totals,
            // A missing asset is blocking: attaching a trade to the wrong
            // instrument, or inventing one from a ticker alone, is worse than
            // asking the user to create it first.
            //
            // Having nothing new is not blocking. Re-pasting a payload that is
            // already fully imported is a legitimate thing to do and its
            // correct outcome is "nothing to do", not an error.
            'can_commit' => $errors === [],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, Asset>  $assets
     * @param  array{positions: array<string, int>, cash_movements: array<string, int>}  $existing
     * @param  array<string, bool>  $seen
     * @param  array<int, string>  $missingAssets
     * @param  array<int, string>  $warnings
     * @return array{bucket: string, row: array<string, mixed>}
     */
    private function classifyHistoryRow(
        array $row,
        Portfolio $portfolio,
        array $assets,
        array $existing,
        array &$seen,
        array &$missingAssets,
        array &$warnings,
    ): array {
        $base = [
            'external_id' => $row['external_id'],
            'command' => $row['command'],
            'symbol' => $row['symbol'],
            'status' => $row['status'],
            'executed_at' => $row['executed_at'],
            'shares' => $row['shares'],
            'price' => $row['price'],
            'fee' => $row['fee'],
            'amount' => $row['amount'],
            'net_amount' => $row['net_amount'],
        ];

        if (! $row['supported']) {
            return ['bucket' => 'skipped', 'row' => $base + [
                'import_status' => self::STATUS_SKIPPED,
                'reason' => sprintf('Unsupported command "%s".', $row['command'] ?: '(blank)'),
            ]];
        }

        if (! $row['completed']) {
            // A cancelled or unmatched order never moved money and must not
            // become a ledger row.
            return ['bucket' => 'skipped', 'row' => $base + [
                'import_status' => self::STATUS_SKIPPED,
                'reason' => sprintf('Status "%s" is not a completed transaction.', $row['status'] ?: '(blank)'),
            ]];
        }

        if ($row['external_id'] === null) {
            // Without a stable id there is no way to keep a re-paste from
            // duplicating this row, so it is refused rather than half-handled.
            return ['bucket' => 'error', 'row' => $base + [
                'import_status' => self::STATUS_ERROR,
                'reason' => 'The transaction has no Stockbit id, so it cannot be de-duplicated on re-import.',
            ]];
        }

        if ($row['symbol'] === '') {
            return ['bucket' => 'error', 'row' => $base + [
                'import_status' => self::STATUS_ERROR,
                'reason' => 'The transaction has no symbol.',
            ]];
        }

        $asset = $assets[$row['symbol']] ?? null;

        if ($asset === null) {
            $missingAssets[] = $row['symbol'];

            return ['bucket' => 'error', 'row' => $base + [
                'import_status' => self::STATUS_ERROR,
                'reason' => sprintf('Missing asset: %s. Create or sync it, then import again.', $row['symbol']),
            ]];
        }

        if ($row['executed_at'] === null) {
            return ['bucket' => 'error', 'row' => $base + [
                'import_status' => self::STATUS_ERROR,
                'reason' => sprintf('Could not read the execution date "%s".', $row['raw_date']),
            ]];
        }

        $isDividend = $row['command'] === StockbitPayloadParser::COMMAND_DIV;
        $ledger = $isDividend ? 'cash_movements' : 'positions';
        $key = Position::SOURCE_STOCKBIT.'|'.$row['external_id'];

        if (isset($existing[$ledger][$key]) || isset($seen[$ledger.$key])) {
            return ['bucket' => $isDividend ? 'dividend' : 'trade', 'row' => $base + [
                'import_status' => self::STATUS_DUPLICATE,
                'reason' => 'Already imported from Stockbit.',
                'asset_id' => $asset->id,
            ]];
        }

        $seen[$ledger.$key] = true;

        return $isDividend
            ? ['bucket' => 'dividend', 'row' => $this->dividendRow($base, $row, $asset)]
            : ['bucket' => 'trade', 'row' => $this->tradeRow($base, $row, $asset, $warnings)];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function tradeRow(array $base, array $row, Asset $asset, array &$warnings): array
    {
        $shares = (float) ($row['shares'] ?? 0);
        $price = (float) ($row['price'] ?? 0);

        if ($shares <= 0 || $price <= 0) {
            return $base + [
                'import_status' => self::STATUS_ERROR,
                'reason' => 'The transaction has no usable share count or price.',
                'asset_id' => $asset->id,
            ];
        }

        $side = $row['command'] === StockbitPayloadParser::COMMAND_SELL
            ? PositionPricing::SIDE_EXIT
            : PositionPricing::SIDE_ENTRY;

        // The exact fee the broker charged is authoritative; the rate is
        // derived from it purely so the column stays meaningful.
        $fee = (float) ($row['fee'] ?? 0);
        $pricing = $this->pricing->normalize($side, $shares, $price, null, $fee);

        // Cross-check the broker's own arithmetic. A mismatch usually means a
        // hand-edited paste, and is worth surfacing rather than importing
        // silently -- but it is not blocking, because the ledger only needs
        // price, quantity and fee.
        $expectedNet = $this->pricing->netAmount($side, $shares, $price, $fee);

        if ($row['net_amount'] !== null && ! $this->moneyMatches($expectedNet, (float) $row['net_amount'])) {
            $warnings[] = sprintf(
                '%s %s on %s: price x shares %s fee is %s but Stockbit reports netamount %s.',
                $row['symbol'],
                $row['command'],
                $row['raw_date'],
                $side === PositionPricing::SIDE_EXIT ? 'minus' : 'plus',
                number_format($expectedNet, 2),
                number_format((float) $row['net_amount'], 2),
            );
        }

        if ($row['amount'] !== null && ! $this->moneyMatches($shares * $price, (float) $row['amount'])) {
            $warnings[] = sprintf(
                '%s %s on %s: price x shares is %s but Stockbit reports amount %s.',
                $row['symbol'],
                $row['command'],
                $row['raw_date'],
                number_format($shares * $price, 2),
                number_format((float) $row['amount'], 2),
            );
        }

        return $base + [
            'import_status' => self::STATUS_NEW,
            'reason' => null,
            'asset_id' => (int) $asset->id,
            'side' => $side,
            'fee_rate' => round($pricing['fee_rate'], 6),
            'effective_unit_price' => round($pricing['avg_price'], 6),
            'net_amount_calculated' => round($expectedNet, 2),
            'payload' => [
                'asset_id' => (int) $asset->id,
                'side' => $side,
                'qty_shares' => $shares,
                'price' => $price,
                'fee_rate' => $pricing['fee_rate'],
                'fee_value' => $pricing['fee_value'],
                'avg_price' => $pricing['avg_price'],
                'executed_at' => $row['executed_at'],
                'source' => Position::SOURCE_STOCKBIT,
                'external_id' => $row['external_id'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function dividendRow(array $base, array $row, Asset $asset): array
    {
        // netamount is what actually landed in the account; amount is the
        // gross. Prefer the former, fall back to the latter.
        $amount = $row['net_amount'] ?? $row['amount'];

        if ($amount === null || (float) $amount <= 0) {
            return $base + [
                'import_status' => self::STATUS_ERROR,
                'reason' => 'The dividend has no usable amount.',
                'asset_id' => (int) $asset->id,
            ];
        }

        $perShare = $row['dividend_per_share'];
        $note = $perShare !== null
            ? sprintf('%s cash dividend — Rp%s/share', $row['symbol'], rtrim(rtrim(number_format($perShare, 4, '.', ''), '0'), '.'))
            : sprintf('%s cash dividend', $row['symbol']);

        return $base + [
            'import_status' => self::STATUS_NEW,
            'reason' => null,
            'asset_id' => (int) $asset->id,
            'kind' => CashMovement::KIND_DIVIDEND,
            'note' => $note,
            'payload' => [
                'kind' => CashMovement::KIND_DIVIDEND,
                'amount' => (float) $amount,
                'executed_at' => $row['executed_at'],
                'note' => $note,
                'source' => CashMovement::SOURCE_STOCKBIT,
                'external_id' => $row['external_id'],
            ],
        ];
    }

    /**
     * Compare the broker's current view against what the ledger computes.
     *
     * Nothing is imported by default. A snapshot is a statement to check the
     * books against, and the transaction history is always the better source
     * of truth -- so the only thing this can create is an explicitly requested
     * opening position for a holding the ledger knows nothing about.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function analyzeSnapshot(Portfolio $portfolio, array $payload, array $options): array
    {
        $parsed = $this->parser->parseSnapshot($payload);
        $wantsOpeningPositions = (bool) ($options['create_snapshot_positions'] ?? false);

        $assets = $this->resolveAssets(array_column($parsed['positions'], 'symbol'));

        $portfolio->loadMissing(['positions.asset.latestPriceRecord', 'cashMovements']);
        $summary = $this->calculator->compute($portfolio);
        $holdings = collect($summary['holdings'])->keyBy(
            static fn (array $holding): string => strtoupper((string) ($holding['symbol'] ?? ''))
        );

        $rows = [];
        $errors = [];
        $warnings = [];
        $missingAssets = [];

        foreach ($parsed['positions'] as $snapshotPosition) {
            $symbol = $snapshotPosition['symbol'];
            $asset = $assets[$symbol] ?? null;
            $holding = $holdings->get($symbol);

            $brokerShares = (float) $snapshotPosition['shares'];
            $ledgerShares = $holding !== null ? (float) $holding['qty'] : 0.0;
            $ledgerAvg = $holding !== null ? (float) $holding['avg_cost'] : null;
            $ledgerCost = $holding !== null ? (float) $holding['cost_basis'] : null;
            $ledgerValue = $holding !== null ? (float) $holding['market_value'] : null;

            $row = [
                'symbol' => $symbol,
                'asset_id' => $asset?->id,
                'broker_shares' => $brokerShares,
                'breakout_shares' => $ledgerShares,
                'shares_match' => abs($brokerShares - $ledgerShares) <= self::SHARE_TOLERANCE,
                'broker_average_price' => $snapshotPosition['average_price'],
                'breakout_average_cost' => $ledgerAvg,
                'average_match' => $snapshotPosition['average_price'] !== null && $ledgerAvg !== null
                    ? abs((float) $snapshotPosition['average_price'] - $ledgerAvg) <= self::PRICE_TOLERANCE
                    : null,
                'broker_amount_invested' => $snapshotPosition['amount_invested'],
                'breakout_cost_basis' => $ledgerCost,
                'invested_match' => $snapshotPosition['amount_invested'] !== null && $ledgerCost !== null
                    ? $this->moneyMatches((float) $snapshotPosition['amount_invested'], $ledgerCost)
                    : null,
                'broker_market_value' => $snapshotPosition['market_value'],
                'breakout_market_value' => $ledgerValue,
                'market_value_match' => $snapshotPosition['market_value'] !== null && $ledgerValue !== null
                    ? $this->moneyMatches((float) $snapshotPosition['market_value'], $ledgerValue)
                    : null,
                'broker_unrealized_pl' => $snapshotPosition['unrealized_pl'],
                'broker_latest_price' => $snapshotPosition['latest_price'],
                'import_status' => self::STATUS_SKIPPED,
                'reason' => 'Reconciliation only; the transaction history stays the source of truth.',
            ];

            if ($asset === null) {
                $missingAssets[] = $symbol;
                $row['import_status'] = self::STATUS_ERROR;
                $row['reason'] = sprintf('Missing asset: %s. Create or sync it, then import again.', $symbol);
                $errors[] = $row;
                $rows[] = $row;

                continue;
            }

            // Only a holding the ledger has never heard of is eligible for the
            // synthetic opening position. Where history already reproduces the
            // quantity there is nothing to add, and adding it would double the
            // position.
            $eligible = $ledgerShares <= self::SHARE_TOLERANCE
                && $brokerShares > 0
                && $snapshotPosition['average_price'] !== null;

            $row['opening_position_eligible'] = $eligible;

            if ($eligible && $wantsOpeningPositions) {
                $shares = $brokerShares;
                $price = (float) $snapshotPosition['average_price'];
                $pricing = $this->pricing->normalize(PositionPricing::SIDE_ENTRY, $shares, $price, null, 0.0);

                $row['import_status'] = self::STATUS_NEW;
                $row['reason'] = 'Synthetic opening position — no history covers this holding.';
                $row['payload'] = [
                    'asset_id' => (int) $asset->id,
                    'side' => PositionPricing::SIDE_ENTRY,
                    'qty_shares' => $shares,
                    'price' => $price,
                    'fee_rate' => $pricing['fee_rate'],
                    // The broker's average already embeds the fees it charged,
                    // so charging one again here would double-count them.
                    'fee_value' => 0.0,
                    'avg_price' => $pricing['avg_price'],
                    'executed_at' => now()->toDateTimeString(),
                    'source' => Position::SOURCE_STOCKBIT_SNAPSHOT,
                    'external_id' => $this->openingExternalId($symbol),
                ];
            } elseif ($eligible) {
                $row['reason'] = 'No history covers this holding. Enable the opening-position fallback to create one.';
                $warnings[] = sprintf(
                    '%s: the broker reports %s shares but no Breakout history explains them.',
                    $symbol,
                    number_format($brokerShares),
                );
            } elseif (! $row['shares_match']) {
                $warnings[] = sprintf(
                    '%s: broker reports %s shares, Breakout calculates %s.',
                    $symbol,
                    number_format($brokerShares),
                    number_format($ledgerShares),
                );
            }

            $rows[] = $row;
        }

        // Once an opening position exists, the ledger explains the holding and
        // the row is no longer eligible -- so without this it would report the
        // generic "reconciliation only". Saying it was already imported is the
        // more useful answer, and matches how a repeated history paste reads.
        $rows = $this->markExistingOpeningPositions($portfolio, $rows);

        return [
            'type' => StockbitPayloadParser::TYPE_SNAPSHOT,
            'portfolio_id' => (int) $portfolio->id,
            'trades' => [],
            'dividends' => [],
            'skipped' => [],
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'missing_assets' => array_values(array_unique($missingAssets)),
            'snapshot' => [
                'positions' => $rows,
                'cash' => $this->reconcileCash($portfolio, $parsed['summary']),
                'broker_summary' => $parsed['summary'],
            ],
            'totals' => $this->totals($rows),
            'can_commit' => $errors === [],
        ];
    }

    /**
     * Work out the base cash balance that makes the calculator agree with the
     * broker.
     *
     * Breakout stores a base cash figure and adds signed cash movements to it.
     * Writing the broker's current cash straight into that base would count
     * every imported dividend a second time, so the proposal is the broker's
     * figure minus whatever the movements already contribute.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>|null
     */
    private function reconcileCash(Portfolio $portfolio, array $summary): ?array
    {
        $brokerCash = $summary['cash_balance'] ?? null;

        if ($brokerCash === null) {
            return null;
        }

        $portfolio->loadMissing('cashMovements');

        $movementsTotal = 0.0;
        foreach ($portfolio->cashMovements as $movement) {
            $movementsTotal += $movement->signedAmount();
        }

        $currentBase = (float) ($portfolio->cash_balance ?? 0.0);
        $proposedBase = (float) $brokerCash - $movementsTotal;

        return [
            'broker_cash' => (float) $brokerCash,
            'current_base_cash' => $currentBase,
            'cash_movements_total' => round($movementsTotal, 2),
            'current_calculated_cash' => round($currentBase + $movementsTotal, 2),
            'proposed_base_cash' => round($proposedBase, 2),
            'adjustment' => round($proposedBase - $currentBase, 2),
            'already_reconciled' => $this->moneyMatches($currentBase + $movementsTotal, (float) $brokerCash),
            'can_reconcile' => true,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function markExistingOpeningPositions(Portfolio $portfolio, array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (($row['symbol'] ?? '') !== '') {
                $ids[] = $this->openingExternalId($row['symbol']);
            }
        }

        if ($ids === []) {
            return $rows;
        }

        $existing = $portfolio->positions()
            ->where('source', Position::SOURCE_STOCKBIT_SNAPSHOT)
            ->whereIn('external_id', $ids)
            ->pluck('external_id')
            ->flip();

        foreach ($rows as $index => $row) {
            if (($row['symbol'] ?? '') === '' || ! $existing->has($this->openingExternalId($row['symbol']))) {
                continue;
            }

            if (($row['import_status'] ?? null) === self::STATUS_ERROR) {
                continue;
            }

            $rows[$index]['import_status'] = self::STATUS_DUPLICATE;
            $rows[$index]['reason'] = 'An opening position for this holding was already imported from a snapshot.';
            unset($rows[$index]['payload']);
        }

        return $rows;
    }

    /**
     * Stable id for a snapshot's synthetic opening position. One per symbol
     * per portfolio -- a snapshot has no transaction id of its own, and this
     * is what the unique index keys on.
     */
    private function openingExternalId(string $symbol): string
    {
        return 'opening:'.strtoupper($symbol);
    }

    /**
     * Look up every symbol at once, case-insensitively.
     *
     * @param  array<int, string>  $symbols
     * @return array<string, Asset>
     */
    private function resolveAssets(array $symbols): array
    {
        $symbols = array_values(array_unique(array_filter(array_map(
            static fn ($symbol): string => strtoupper(trim((string) $symbol)),
            $symbols,
        ))));

        if ($symbols === []) {
            return [];
        }

        return Asset::query()
            ->whereIn(DB::raw('UPPER(symbol)'), $symbols)
            ->get(['id', 'symbol'])
            ->keyBy(static fn (Asset $asset): string => strtoupper((string) $asset->symbol))
            ->all();
    }

    /**
     * Which broker ids this portfolio already holds, per ledger.
     *
     * @param  array<int, string>  $externalIds
     * @return array{positions: array<string, int>, cash_movements: array<string, int>}
     */
    private function existingExternalIds(Portfolio $portfolio, array $externalIds): array
    {
        $externalIds = array_values(array_unique($externalIds));

        if ($externalIds === []) {
            return ['positions' => [], 'cash_movements' => []];
        }

        $positions = $portfolio->positions()
            ->whereNotNull('source')
            ->whereIn('external_id', $externalIds)
            ->get(['id', 'source', 'external_id']);

        $movements = $portfolio->cashMovements()
            ->whereNotNull('source')
            ->whereIn('external_id', $externalIds)
            ->get(['id', 'source', 'external_id']);

        $key = static fn ($row): string => $row->source.'|'.$row->external_id;

        return [
            'positions' => $positions->keyBy($key)->map->id->all(),
            'cash_movements' => $movements->keyBy($key)->map->id->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function totals(array $rows): array
    {
        $totals = [
            self::STATUS_NEW => 0,
            self::STATUS_DUPLICATE => 0,
            self::STATUS_SKIPPED => 0,
            self::STATUS_ERROR => 0,
            'rows' => count($rows),
        ];

        foreach ($rows as $row) {
            $status = $row['import_status'] ?? self::STATUS_SKIPPED;

            if (array_key_exists($status, $totals)) {
                $totals[$status]++;
            }
        }

        return $totals;
    }

    /**
     * Two money figures agree to the rupiah, or to a hundredth of a percent on
     * large values -- whichever is looser.
     */
    private function moneyMatches(float $a, float $b): bool
    {
        $tolerance = max(self::MONEY_ABS_TOLERANCE, abs($b) * self::MONEY_REL_TOLERANCE);

        return abs($a - $b) <= $tolerance;
    }
}
