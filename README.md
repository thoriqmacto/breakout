# Breakout

This repository contains the code for **Breakout**, structured as a multi-app monorepo. It currently includes a Laravel API (`apps/api`) and a Next.js front-end (`apps/web`).

## Technology
- PHP ^8.2 with Laravel ^12.0 as the web framework and tinker for interactive shells
- Next.js 15 with Tailwind CSS 4 for the front-end application

## Development
1. Install PHP dependencies:
   ```bash
   composer install
   ```
2. Install JavaScript dependencies:
   ```bash
   npm install
   ```
3. Copy `apps/api/.env.example` (or `apps/api/.env.local.example` for a local dev preset) to `apps/api/.env` and configure your environment. **Never commit `.env*` files with real credentials**: the repository's root and `apps/api` `.gitignore` files exclude all `.env` and `.env.*` variants except the `*.example` templates. If you accidentally paste a real API key (Stockbit bearer, Marketstack key, AWS, etc.) into a tracked file, rotate the key immediately.
4. Start the local development stack:
   ```bash
   composer dev
   ```
   This script runs the Laravel server, queue listener, log viewer, and Vite asset builder concurrently

## Available Commands
- Build frontend assets:
  ```bash
  npm run build
  ```
- Run the automated test suite:
  ```bash
  composer test
  ```
  The test script clears configuration and executes `php artisan test`

- Run the asset sync command with all options:
  ```bash
  php artisan asset:sync --check-python --run-python --import-csv --continue --chk-date=2025-08-01
  ```
  Or run with all confirmations accepted using today's date:
  ```bash
  php artisan asset:sync --eod
  ```

- Rebuild or restore the recovery layer (see [Data resilience](#data-resilience-the-three-layers)):
  ```bash
  php artisan data:reconcile --all --mirror   # rebuild what changed, publish to cold storage
  php artisan data:restore --all --dry-run    # what a recovery would write
  ```

- Inspect the database-managed scheduler (see [Automation & Scheduling](#automation--scheduling)):
  ```bash
  php artisan scheduler:status          # every automation, its next run and last outcome
  php artisan schedule:list             # the single static entry that drives them
  php artisan scheduler:dispatch        # run whatever is due right now
  ```

## Updating Asset Data (step-by-step)
The Laravel console already includes helpers for refreshing the trading calendar, syncing OHLCV data, and checking integrity. Run them in this order when updating datasets:

1. **Refresh the trading day calendar** – keeps `trading_days` aligned with Yahoo Finance and updates the seeder:
   ```bash
   php artisan trading-days:build --from=2015-01-01 --to=$(date +%F)
   ```
   Adjust the `--from` / `--to` range as needed; rerunning is idempotent and will rewrite `database/seeders/data/trading_days.php` when new rows are found (`--no-seeder-sync` skips that). See [Trading-day integrity](#trading-day-integrity) for what the command reports and why it can fail.

2. **Sync OHLCV seeds and the database** – compare configured symbols to seed CSVs, optionally pull missing data via the Python scripts, and upsert bars into `price_bars`:
   ```bash
   php artisan asset:sync --eod
   ```
   This commands to auto-enable all required options with today's date and to skip prompts. The command will retrieve price data from Stockbit (Bearer token prompt required) and make Python downloader for Yahoo Finance as the backup.

   For a **single asset historical refresh**, use Stockbit scraper with `--historical` and rely on its default date window (IPO date to latest trading day):
   ```bash
   php artisan stockbit:scrape BBCA --historical
   ```
   Replace `BBCA` with your target symbol (for example `INCO` or `ANTM`).

3. **Verify and repair gaps or duplicates** – scan configured symbols and optionally resolve issues:
   ```bash
   php artisan ohlcv:check --all
   ```
   Simply checking across all tickers integrity of historical data with detail reports for each tickers.
   ```bash
   php artisan ohlcv:check --all --resolve=missing-days
   ```
   Provide a symbol to target a single asset instead of every configured index symbol:
   ```bash
   php artisan ohlcv:check INCO --resolve=missing-days
   ```
   Use `--resolve=extra-bars` to prune stray rows or `--resolve=missing-days --force-delete` when you want missing trading days removed from the calendar after attempted recovery.

## Feature Extraction Usage
Use the feature extraction command to persist daily OHLCV and broker metrics into `features_daily`.

On a configured deployment you rarely need to run this by hand: the seeded **Daily Analysis
Refresh** automation runs it every evening behind the scrapes, along with asset metrics, broker
rollups, watchlist scores and the saved strategies. See
[Automation & Scheduling](#automation--scheduling). The command below is still the way to rebuild
a specific symbol or an arbitrary range.

- Extract features for the latest trading day per asset:
  ```bash
  php artisan features:extract
  ```
- Extract features for a single symbol on a specific date:
  ```bash
  php artisan features:extract --symbol=BBCA --date=2025-01-31
  ```
- Extract features for a date range (inclusive):
  ```bash
  php artisan features:extract --from=2025-01-01 --to=2025-01-31
  ```
- Limit how many symbols are processed in a run:
  ```bash
  php artisan features:extract --limit=10
  ```

### Feature Extraction Glossary
Below is a quick reference for the feature abbreviations emitted by `features:extract`, along with typical value ranges and meanings.

**OHLCV-derived features**
- `ret_1` — 1-day return `(close_t / close_{t-1}) - 1`. Range: -1.0+; 0 means unchanged, positive means up day, negative means down day.
- `range_pct` — intraday range `(high - low) / close`. Range: 0.0+ (percentage as a ratio).
- `close_pos` — close position within range `(close - low) / (high - low)`. Range: 0.0–1.0 (0 = closed at low, 1 = closed at high).
- `body_to_range` — candle body size relative to range `abs(close - open) / (high - low)`. Range: 0.0–1.0.
- `vol_ratio_20` — volume vs 20-day SMA `volume / SMA20(volume)`. Range: 0.0+ (1.0 = average volume).
- `atr_pct` — 14-day ATR scaled by close `ATR14 / close`. Range: 0.0+ (percentage as a ratio).
- `close_vs_ma20` — close vs 20-day SMA `(close / MA20) - 1`. Range: typically negative to positive; 0 means equal to MA20.
- `ma20_slope` — slope of the last 5 points of the MA20 series. Range: unbounded; positive = rising MA20.
- `breakout20` — 20-day high breakout flag. Range: 0 or 1 (1 = close above prior 20-day high).
- `compression` — volatility compression flag (ATR% below its 10-day SMA). Range: 0 or 1.

**Broker/flow features**
- `has_broker` — indicates broker data exists for the day. Range: 0 or 1.
- `turnover_value` — total traded value from Bandar detector. Range: 0.0+ (currency value).
- `turnover_volume` — total traded volume from Bandar detector. Range: 0+ (shares).
- `accdist_score` — accumulation/distribution score. Range: -1 (distribution), 0 (neutral/unknown), 1 (accumulation).
- `avg_net_norm` — average net value normalized by turnover value. Range: roughly -1.0 to 1.0; can exceed in edge cases when metrics are noisy.
- `avg5_net_norm` — 5-day average net value normalized by turnover value. Range: similar to `avg_net_norm`.
- `top1_net_norm` / `top3_net_norm` / `top5_net_norm` / `top10_net_norm` — top-N net value normalized by turnover value. Range: typically -1.0 to 1.0; magnitude reflects dominance.
- `buyer_count` — number of brokers with net buying. Range: 0+ (integer).
- `seller_count` — number of brokers with net selling. Range: 0+ (integer).
- `active_broker_count` — unique broker codes active that day. Range: 0+ (integer).
- `buyer_hhi` / `seller_hhi` — Herfindahl–Hirschman Index on buyer/seller shares. Range: 0.0–1.0 (higher = more concentration).
- `top1_sell_share` / `top3_sell_share` / `top5_sell_share` — share of total turnover contributed by top N net sellers. Range: 0.0–1.0.
- `net_to_gross_ratio` — absolute average net vs total gross value. Range: 0.0–1.0.
- `foreign_net_norm` / `local_net_norm` / `gov_net_norm` — net value by broker type normalized by turnover value. Range: typically -1.0 to 1.0; sign indicates net buy/sell.

**Cross/derived signals**
- `absorption_flag` — accumulation + bullish OHLCV setup. Range: 0 or 1.
- `dist_breakdown` — distribution + weak OHLCV setup. Range: 0 or 1.
- `stealth_acc` — accumulation + low-volatility setup. Range: 0 or 1.
- `bandar_dist_hard` — strong distribution risk flag. Range: 0 or 1.
- `valid_long_setup` — true when distribution flags are not triggered. Range: 0 or 1.

**Labels**
- `y_hit_5d` — classification label for +3.5% target within 5 trading days. Range: 1 (hit target), 0 (did not hit), null (insufficient data).
- `dd_5d` — reserved placeholder for drawdown (currently null).


## Trading-day integrity

`trading_days` carries two different facts, and conflating them is what caused a
production incident where a real session's IHSG close sat at NULL for days while
every repair attempt reported success.

| | Meaning |
| --- | --- |
| a row exists | that session happened — this is what the calendar and every market-day condition read |
| `close` is a number | we know what the IHSG closed at |
| `close` is NULL | we do **not** know. Not zero, and not a holiday. |

Four invariants follow, and all four are enforced in code rather than by
convention:

1. **A known close is stronger information than an unknown one.** Unknown never
   overwrites known.
2. **A numeric close always repairs a NULL.** An incomplete row heals itself the
   next time *any* source supplies the figure — the provider, or the
   version-controlled ledger. No manual SQL.
3. **A missing close never makes a session look like a holiday.** The row's
   existence is what records the session.
4. **A command must not report success when the provider supplied a number the
   database did not store.**
5. **A close that has ever been recorded must not be lost because one import
   went badly.** The ledger is merged, never overwritten.

### One writer

Every path that touches `trading_days.close` goes through `TradingDayWriter`,
which splits the write rather than trusting its caller: rows carrying a close are
upserted and update the column, rows carrying none are inserted-or-ignored and
cannot touch it. No database-specific SQL, so the guarantee is identical on
SQLite and MariaDB.

The Yahoo importer, the seeder and the automation refresh all use it. There is no
second spelling of the rule to keep in step.

### The checked-in ledger

`database/seeders/data/trading_days.php` is the only copy of the calendar that
lives outside the database, and it is version controlled — so a close it has
recorded survives any number of bad provider responses. `TradingDayLedger` gives
it the same rule the table has, in both directions:

- **Reading.** When Yahoo will not supply a close the table is missing,
  `trading-days:build` fills it from the ledger before writing the run off as
  incomplete. Only dates the file already holds a number for are touched, so
  there is nothing session-specific about it, and `--no-ledger-repair` turns it
  off. This is the repair path that needs no network: if the value was ever
  committed, the row can always be healed.
- **Writing.** The file used to be rewritten from the database verbatim at the
  end of every run. One import where the provider omitted a session's value was
  therefore enough to erase the number from the ledger too — and with both
  copies unknown, nothing was left to repair from. Writes now merge: a session
  the database knows nothing about keeps whatever the file already recorded.

`php artisan db:seed --class=TradingDaySeeder` applies the same repair on its
own, which is the fastest way to restore a close the deployed database has lost
but the repository still has.

### Fetch range vs persistence range

The provider is asked for more than is stored:

```
requested:  2026-08-28 → 2026-08-31
fetched:    2026-08-21 → 2026-08-31     (TRADING_DAYS_FETCH_BUFFER_DAYS, default 7)
persisted:  2026-08-28 → 2026-08-31
```

A download window that begins exactly on the session you need has been observed
to come back without it, which is how a repair silently does nothing: the row
stays NULL because the date was never in the response. Records outside the
requested range are discarded before any write, so widening the buffer changes
what is fetched and never what is stored. Seven calendar days clears a weekend
plus an ordinary market holiday.

### Verified persistence

`trading-days:build` gathers the provider's answer and the database's answer
separately and compares them:

```
Yahoo returned (fetched from 2026-08-21):
  3 trading sessions
  3 with close values
Database after import:
  3 trading sessions
  3 with close values
  0 null closes
Repaired null closes from Yahoo: 2026-08-28
```

If Yahoo supplied a close for a date the table still holds as NULL, the command
**fails** rather than printing a count. If Yahoo had nothing for a session, the
ledger is asked next (`Repaired null closes from the checked-in ledger: …`), and
only when neither knows the value is it a warning and the row stays honestly
incomplete.
`automation:trading-calendar-refresh` reports the same condition as
`null_close_count` / `null_close_dates` and marks the run partial.

### The date key

`trading_days.date` is the primary key, and it is stored as a bare `Y-m-d`. It
was not always: the model's `date` cast made Eloquent write
`2026-08-28 00:00:00` while every query-builder write produced `2026-08-28`. On
an engine that does not coerce the column those are two keys for one session, so
an upsert never conflicted and inserted a second row whose NULL then shadowed the
good close on any ordered read. A mutator normalises model writes, and
`2026_09_01_000000_normalize_trading_day_date_keys` merges any rows already split
across both spellings, keeping the known close.

The same mismatch made a string `BETWEEN` drop its own upper bound — a stored
`2026-08-31 00:00:00` sorts after the bare `2026-08-31` it is compared against —
so every date-range read now compares as dates. In `ohlcv:check` the truncation
had been happening on both sides at once and cancelling out; with one side fixed
it surfaced as a bar that exists being reported missing.

## Analysis: structure, execution, and the line between them

Two different questions used to share one word.

**Structural strength** — *which stocks are strong relative to the universe?* Trend, momentum, and
where price sits against its own 20- and 55-week highs. Slow-moving, and it says nothing about
timing. Surfaced on `/dashboard/assets`, `asset:metrics`, and `GET /v1/assets/metrics` as
**structural rank**.

**Execution readiness** — *which current setups may be actionable next session?* Broker
accumulation, breakout confirmation, liquidity, and a risk/reward measured at the price you would
actually pay. Surfaced on `/dashboard/execution` and `GET /v1/execution/candidates` as
**execution rank**.

A stock can be structurally excellent and not executable. That is the ordinary case, and it is why
one number could never serve both.

### One canonical calculation

`AssetTechnicalSnapshotService` is the only place a technical metric is computed. Before it, the
same formulas were written three times — in `AssetMetricsCommand`, in
`AssetController::updateMetrics()`, and implicitly in whatever the ranker read out of `metrics` —
and they had already drifted:

| | Old CLI ranking | Old API ranking |
| --- | --- | --- |
| 1 | uptrend | uptrend |
| 2 | **ROC13** | **PBAS** |
| 3 | close vs 55wH | close vs 55wH |
| 4 | close vs 20wH | close vs 20wH |
| 5 | volume ratio | volume ratio |

Both columns were headed "Rank". The canonical ordering is the CLI's:
`uptrend → ROC13 → close/55wH → close/20wH → volume ratio`, defined once in
`AssetTechnicalSnapshot::structuralSortKey()`. PBAS is a broker-accumulation signal and is scored in
the execution pipeline; it has no place in a structural ordering.

The CLI, the API, the scheduler and the watchlist ranker are all thin callers of that one service.

### Corporate actions and the long windows

Bars are stored **unadjusted**: a split leaves pre-split and post-split prices in the same series,
at face value. That is harmless for the short formulas and not harmless for the long ones. The
55-week high looks back 275 sessions, so for most of a year after a split it still reaches prices
from before it.

MLPT is the worked example. It split roughly 1:27 on 2026-05-21 (close 20,475 → 770), and on
2026-09-02 its 55-week high was still a pre-split 257,875 against a close of 1,260 — a multiple of
205. Used as a take-profit that produced a risk/reward of 12,830.75, which does not fit
`watchlist_scores.risk_reward` (`decimal(8,4)`), and the insert took the whole watchlist step down
with it.

Two guards, in `RiskCalculator`:

- **`MAX_TARGET_MULTIPLE` (10.0).** A 55-week high more than ten times the reference price is not
  resistance and is not used as a target; the row falls back to the minimum-target rule and
  `risk_notes` records the rejected value. Ten is chosen from the data rather than taste: the
  largest genuine drawdown in the current universe puts its 55-week high at 5.3× the close, and
  the contaminated case sits at 205×, so the threshold has a wide margin either side of the gap.
  `ExecutionPlanner` applies the same rule through `RiskCalculator::isUsableTarget()`, because it
  builds its target from the same input and sorts candidates by the resulting ratio.
- **`MAX_RISK_REWARD`.** A final ceiling matching what the column can store. The target check
  already keeps the ratio far below it; reaching it means an input the class did not anticipate,
  and capping beats aborting the pass.

Neither guard repairs the series. **`close_vs_high55` is computed upstream and is still wrong for
a split-contaminated symbol** — MLPT reads as 99.5% below its one-year high — and that field is
the third structural sort key, so such a symbol is still mis-ranked. Detecting corporate actions,
or storing an adjustment factor, is a separate piece of work.

`strategy:rank-watchlist` also isolates per-symbol write failures now. Rows are written in score
order, so an exception part way through used to keep everything above the offending symbol and
discard everything below it — with which symbols survived depending on where the bad one happened
to rank. Failures are collected, listed by symbol, and the command exits non-zero: one symbol
missing from the watchlist should be loud, not absorbed.

> **A note on the test suite.** The suite runs on SQLite, which does not enforce `DECIMAL`
> precision; production runs on MariaDB, which does. An out-of-range decimal is therefore
> invisible to CI, which is why this reached production green. Tests around numeric ranges assert
> the *value*, not that the insert succeeds.

### As-of, never latest

Every snapshot is built only from bars dated on or before the date requested, so a snapshot for
2026-04-01 is identical whether or not the database also holds April, May and June. Retrieval is
bounded at 300 bars (the deepest formula, the 55-week high, needs 275), so an asset with fifteen
years of history costs the same as a new listing.

The `metrics` table remains what it always was — a cache of the *latest* snapshot, one row per
asset, convenient for a list view. It is never historical truth. `WatchlistRanker` used to take
ATR14 and the 55-week high from it regardless of the scan date, so backfilling March scored March's
trades against September's volatility and September's highs. It now reads an as-of snapshot.

## Execution workspace

`/dashboard/execution` is the one page for deciding what may be actionable next session. It composes
rather than recomputes: technicals from the snapshot service, scores from the watchlist pipeline
that already produced them, the entry plan from `ExecutionPlanner`.

### T → T+1

A signal computed from session **T**'s completed bar does not exist until T has closed. It therefore
cannot be executed at T's close — that price is gone by the time the signal exists.

Every candidate carries `signal_date` and `next_trading_date`, and the second comes from
`trading_days`, never from signal date + 1. A Friday signal is actionable on Monday; a signal before
a holiday on the day after it.

### The planned entry trigger

Rather than "buy because rank is high", each candidate names a level the next session must actually
reach:

```
reference = max(signal session high, highest high of the 20 sessions before it)
trigger   = one IDX tick above that reference
```

Both terms are known at T, and taking the higher means the trigger is always above everything that
traded during the signal session — so a fill there is a continuation, never a retrospective fill at
a price the signal itself caused. The 20-session reference is the same one
`FeatureExtractionService` uses for `breakout20`, so a planned trigger and a stored feature cannot
disagree.

**Risk/reward is then recomputed at the trigger, not at the close.** The stop does not move when the
entry does, so a setup can clear 2.0 R measured from Friday's close and fail it measured from the
price you would really pay on Monday. Both numbers are shown: `planned_risk_reward` and
`signal_close_risk_reward`. Only the first can make a candidate READY.

### Statuses

Rule-based and stated in full, applied in this order. Every candidate carries the reasons that
decided it.

| Status | Rule |
| --- | --- |
| **AVOID** | Hard distribution (`bandar_dist_hard`), or not a valid long setup, or no measurable invalidation level. Checked first: knowing a setup is broken does not depend on it being fresh. |
| **STALE** | The signal is not from the latest completed session, or the broker window ends more than `execution.freshness.max_broker_lag_days` before it. |
| **READY** | Fresh, in uptrend, liquidity filter passes, risk/reward filter passes, score ≥ `execution.min_score`, **and** risk/reward at the planned trigger ≥ `execution.min_rr`. |
| **WATCH** | Anything else. Worth following, at least one rule unmet, no entry plan attached. |

### Thresholds are configuration, not findings

`config/execution.php`:

```php
'min_score'         => 75.0,  // where the UI already drew its "strong" band
'min_rr'            => 2.0,
'max_entry_gap_pct' => null,  // disabled by default
```

75 is adopted because the interface already treated it as meaningful, **not** because it has been
shown to provide lift. Backtesting is what should decide that, which is why it is a config value
with an env override rather than a constant.

`max_entry_gap_pct` is null on purpose: no gap threshold has been validated here, and inventing one
would dress a guess as a finding. The risk/reward recomputation is the real protection — a gap that
ruins the reward fails `min_rr` on its own.

## The execution strategy (execution-v2)

Everything above describes the v1 watchlist: what a setup looks like today. The v2 profile adds the
half that was missing — what setups like it have historically done, and how a position is managed
once it exists.

```
BROKER ACCUMULATION -> SETUP -> TRIGGER -> POSITION
  -> +5% PROFIT ACTIVATION -> 2% TRAILING STOP -> EXIT
```

**The +5% level is not a profit target and never a promise.** It is the threshold that switches the
trailing stop on. What the system can honestly say about it is empirical:

> P(+5% before stop) — the share of *comparable historical setups* that reached +5% before their
> initial stop.

That is a frequency measured over past data, not a prediction, not advice, and not a guarantee.

### Broker windows, read as four questions

The 3/5/10/20-day rollups in `broker_accumulation_windows` already existed. What changed is that
they are no longer averaged into one number:

| Window | Question |
| --- | --- |
| 3D | short-term acceleration |
| 5D | near-term confirmation |
| 10D | primary accumulation regime |
| 20D | background accumulation regime |

A length-weighted average answers "how much net buying" and cannot answer "for how long", and those
differ exactly where it matters:

```
A:  3D +0.09   5D -0.01   10D -0.02   20D -0.03
B:  3D +0.01   5D +0.01   10D +0.01   20D +0.01
```

The average ranks A above B on the strength of one unusual session. B is the one that looks like
somebody accumulating. `BrokerFlowAnalyzer` reports `broker_persistence_ratio`,
`positive_broker_windows`, `available_broker_windows`, `broker_acceleration`, `top3_net_norm`,
`avg_net_norm`, consistency, active brokers and concentration, and classifies a **regime** from the
medium-term windows only — 3D alone can never declare one.

| Regime | Rule |
| --- | --- |
| `STRONG_ACCUMULATION` | every medium-term window positive **and** top-3 net ≥ `broker_strong_top3_norm` |
| `ACCUMULATION` | more medium-term windows positive than negative |
| `NEUTRAL` | no consistent direction, or no medium-term window at all |
| `DISTRIBUTION` | more medium-term windows negative than positive |
| `STRONG_DISTRIBUTION` | every medium-term window negative **and** top-3 net ≤ −`broker_strong_top3_norm` |

A window counts as directional only beyond `broker_flow_epsilon`; below it the flow is noise.

### Lifecycle statuses

| Status | Meaning |
| --- | --- |
| `WATCH` | Broker flow is interesting; the price setup is not ready. |
| `ARMED` | Accumulating and within `armed_distance_atr` of the breakout level. |
| `TRIGGERED` | Breakout confirmed; actionable next session inside the entry zone. |
| `NO_CHASE` | Triggered, but price has run past the entry zone. A state, not a hidden row. |
| `HOLD` | Held, below the trailing activation level. |
| `TRAILING` | Held above activation; the trailing stop is live. |
| `EXIT` | An exit condition has been met on an open position. |
| `AVOID` | Distributive regime, invalid plan, or risk beyond the ceiling. |
| `STALE_DATA` | The signal is current; its broker or price inputs are not. |

`READY`, `WATCH`, `AVOID` and `STALE` remain for v1 rows so stored history and the existing API
contract keep their meaning. A symbol the portfolio already holds is reported as a holding and
never simultaneously as an unrelated watchlist candidate.

### Stale broker data blocks execution

Analysis tolerates broker data a few sessions old; execution does not. Execution candidates require
broker rollups within `max_broker_lag_days_execution` (default 1) of the signal session — otherwise
the row is `STALE_DATA` and cannot be `TRIGGERED`. The looser
`execution.freshness.max_broker_lag_days` still governs the v1 status.

One session of *lead* is not staleness: the plan is for T+1, so a bar existing for T+1 is the
situation the plan was written for.

### Entry: a zone, not a price

```
trigger_price   = one IDX tick above max(signal high, prior 20-session high)
entry_zone_low  = trigger_price
entry_zone_high = trigger_price + max_entry_extension_atr × ATR14     (default 0.5 ATR)
```

Measured in ATR rather than percent, because "1% above" means different things on a quiet stock and
a volatile one. Past the zone the setup may be fine and the entry is not — that is `NO_CHASE`.

### Initial stop, and rejection rather than resizing

```
volatility stop = breakout_level − initial_stop_atr_multiple × ATR14   (default 1.0 ATR)
structural stop = 20-session swing low
initial_stop    = the tighter of the two that still sits below the trigger
```

Measured from the **breakout level**, not the close: the level is where the idea is, and sizing risk
off an extended close would widen the stop on exactly the entries that deserve the tightest one.

If `initial_risk_pct` exceeds `max_initial_risk_pct` (default 4%) the plan is **rejected**, not
resized. Sizing down converts a bad setup into a small bad setup.

### +5% activation, 2% trail, +3% floor

Before activation the position is managed by its structural stop. Once the highest price since entry
reaches `entry × 1.05`:

```
profit_floor  = entry × 1.03
trailing_stop = highest_since_entry × 0.98
effective_stop = max(profit_floor, trailing_stop)      and may never move down
```

Worked example:

```
entry 1000, high 1050
trail  1050 × 0.98 = 1029
floor  1000 × 1.03 = 1030
stop   max(1029, 1030) = 1030
```

The floor is what makes activation worth having: a 2% trail off a price only just above +5% would
sit at +2.9%, and one ordinary pullback would give the move back.

**+3% is a price level, not a guaranteed return.** Gaps can open below it, fills slip, and fees come
off whatever is realised. The engine models the first two — a session opening below the stop exits
at the open, not at the stop — and `TradingCostModel` handles the third. The workspace shows the
round-trip cost next to the floor for exactly this reason.

Persisted per holding in `position_risk_states`: `highest_price_since_entry`, `trailing_active`,
`trailing_activated_at`, `trailing_activation_price`, `profit_floor_price`, `trailing_stop_price`,
`effective_stop_price`, `stop_updated_at`. Stored rather than recomputed because a stop derived
fresh on each request has no memory of where it has already been, and the ratchet is the guarantee.

### Broker deterioration while holding

One window turning negative is not an exit.

| Condition | Action |
| --- | --- |
| 5D and 10D still positive | `HOLD` |
| 3D negative, medium-term still accumulating | `HOLD_TIGHTEN_STOP` |
| 3D **and** 5D negative | `EXIT_WARNING` |
| 3D and 5D negative **and** price structure weakening | `EXIT_WARNING` (higher severity) |

Broker flow never moves the price stop on its own: the stop is where the idea is wrong, and broker
flow is evidence about the idea, not about the level.

### Historical outcomes and P(+5% before stop)

`strategy:evaluate-outcomes` reconstructs every scored session as it stood, then looks forward.

Two questions, deliberately answered by two separate passes:

- **The probability pass** — fixed initial stop, no trailing, no costs. A property of the *setup*, so
  it does not move when the trailing parameters are tuned.
- **The lifecycle pass** — the real trailing engine and the real cost model. A property of the
  *strategy*, and the basis for expectancy and profit factor.

Stored per `(asset, signal_date, strategy_version)` in `strategy_signal_outcomes` with MFE/MAE at
1/3/5/10/20 sessions, `hit_5pct`, `days_to_5pct`, `hit_stop_before_5pct`,
`reached_5pct_before_stop`, the trailing outcome, gross and net return, and `resolved`.

Comparability is the `setup_bucket`: broker regime × breakout state × volume band × initial-risk
band. Probability is looked up by bucket, falling back to a coarser one (regime × breakout) when the
exact bucket is thin — and the response says which was used.

**Below `minimum_probability_sample` (default 30) no rate is shown at all**, only
`INSUFFICIENT_SAMPLE`. A hit rate over eleven trades rendered to one decimal place invites a decision
it cannot support. Unresolved trades are excluded rather than counted as misses, which would bias
every statistic downward exactly at the recent end of the data.

### Anti-look-ahead rules

The strategy must be runnable for any historical scan date without reading anything later:

- Technicals come from `AssetTechnicalSnapshotService`, which is as-of by construction. The `metrics`
  table is a cache of the *latest* snapshot and is never consulted historically.
- Broker rollups are filtered to `end_date <= signal_date`.
- Probability lookups take an `as_of` and exclude outcomes whose signal date is not strictly earlier
  — look-ahead laundered through a statistic is still look-ahead.
- In `SignalOutcomeEvaluator` no object holds both halves: signal construction is handed nothing
  dated after the signal session, the simulator nothing dated before the entry.

Asserted directly in `tests/Feature/Strategy/SignalOutcomeEvaluatorTest.php`: rewrite the future
bars and every signal field must be unchanged while the outcome fields move.

### Daily-candle ambiguity

Daily bars do not reveal intraday sequence:

```
Open 1090   High 1130   Low 1070   Close 1115
```

If a session's range contains both a new trailing high and the stop, the data cannot say which came
first. `intraday_assumption` defaults to `conservative`: the stop is checked against the level in
force at the *start* of the session, so such a day is an exit and the new high never gets to raise
the stop that would have saved it. `optimistic` exists only so the cost of the assumption can be
measured. Reported rates are therefore conservative rather than flattering.

### Trading costs

`config/strategy_profile.php` → `costs`: `buy_fee_pct` (0.15), `sell_fee_pct` (0.25),
`slippage_pct` (0.10), `round_to_tick`. Slippage is applied as an adverse price adjustment on both
sides — you buy a little higher and sell a little lower — then rounded back onto the IDX tick ladder
*away* from the trade, so rounding cannot hand back part of the slippage. Both `gross_return_pct`
and `net_return_pct` are always produced; the gap between them is itself information.

### The parameter grid

`strategy:backtest-execution` compares five activation/trailing/floor combinations **on identical
signals**, split chronologically by trade count into in-sample, validation and out-of-sample.

Reporting 5/2/3 on its own would answer a much easier question. What is worth knowing is whether it
sits on a plateau — neighbours performing similarly, so the choice is robust — or on a spike, where
half a point either way collapses the result and the number is noise mistaken for an edge. The grid
is five cells, not five hundred: every extra cell is another chance to fit this particular history.

```bash
php artisan strategy:backtest-execution --from=2024-01-01 --to=2026-06-30
```

Drawdown assumes one equally weighted position at a time and no compounding.

### Parameters

Every tunable lives in `config/strategy_profile.php` and reaches services through a
`StrategyProfile` value object, so a simulation can vary one without mutating global state and every
stored outcome records the profile version that produced it.

```php
'version'                    => 'execution-v2',
'broker_windows'             => [3, 5, 10, 20],
'trail_activation_gain_pct'  => 5.0,
'trailing_distance_pct'      => 2.0,
'minimum_locked_profit_pct'  => 3.0,
'max_entry_extension_atr'    => 0.5,
'max_initial_risk_pct'       => 4.0,
'min_volume_ratio'           => 1.3,
'preferred_volume_ratio'     => 1.5,
'min_close_position'         => 0.70,
'minimum_probability_sample' => 30,
```

**None of these defaults is a validated edge.** They are starting points, and the grid exists so
they can be argued with.

### Running it

```bash
# Daily: the refresh already chains this behind the watchlist step.
php artisan automation:analysis-refresh

# Backfill forward outcomes over a longer history.
php artisan strategy:evaluate-outcomes --from=2024-01-01 --to=2026-08-31

# Compare parameters.
php artisan strategy:backtest-execution --lookback=730

# The workspace reads the result.
open /dashboard/execution
```

`strategy:evaluate-outcomes` is idempotent per `(asset, signal_date, strategy_version)` and runs
over a trailing window by default, which is what converts recent unresolved signals into answers as
the bars arrive.

> **Research and decision support only.** `P(+5% before stop)` is an empirical historical frequency,
> not a prediction and not a guarantee. Nothing on these surfaces is advice.

## Backtesting from T+1

`strategy:backtest-watchlist` enters on the session **after** the signal. The previous version
entered at the signal session's close, so every statistic it produced was measured from a price
that was already unavailable when the signal was generated — an edge borrowed from the future,
spread evenly across every bucket.

Two fill models:

```bash
php artisan strategy:backtest-watchlist --entry=next_open          # fill at T+1's open
php artisan strategy:backtest-watchlist --entry=breakout_trigger   # fill only if T+1 trades through
php artisan strategy:backtest-watchlist --entry=breakout_trigger --max-gap-pct=0.05
php artisan strategy:backtest-watchlist --min-rr=2.0 --horizons=5,10,20
```

- **`next_open`** — the simplest honest assumption, and the floor any other model has to beat.
- **`breakout_trigger`** — if T+1 never touches the trigger there is no trade; if it opens *above*
  the trigger the fill is the open, never the trigger, because you cannot buy below the session's
  first print.

A fill is not automatically a trade. The risk levels were fixed at T, so a gap moves the entry
without moving the stop: every fill is re-measured at the price paid and rejected if the resulting
risk/reward falls below the minimum. The report's **signal flow** table counts what happened to
every signal — filled, never triggered, rejected on R/R at the fill, no next session, missing data —
so a promising-looking hit rate over four surviving trades is visible as exactly that.

Horizons are counted in trading sessions from the entry, never in calendar days. The same correction
was applied to `features_daily.y_hit_5d`, whose calendar-day window reached Wednesday from a Friday
and Saturday from a Monday, so it almost never found five bars and returned null for most of the
week.

MFE and MAE come from the same bars and separate a flat winner from one that spent a week underwater.

`strategy:rank-watchlist` now persists **every** evaluated asset; `--top` bounds only what the
caller is handed back. Keeping just the best thirty rows made every later statistic a claim about an
already-selected population while reading like one about the universe.

## Portfolio cash accounting

Available cash is three terms:

```
  base cash                 what the portfolio opened with
+ non-trade movements       deposits, withdrawals, dividends, standalone fees, adjustments
+ signed trade settlements  -(qty*price + fee) per entry, +(qty*price - fee) per exit
= available cash
```

The third term used to be missing entirely. Cash was `base + movements` only, so **selling a
holding correctly reduced the position and booked a realized gain while the money never
appeared**, and buying one never cost anything. Total equity was wrong by the full traded amount in
both directions.

**Realized P/L is never added to cash.** An exit's proceeds already contain the gain or loss; adding
it again would count it twice. It is reported separately, and the identity that must hold is:

```
total equity = available cash + current market value
```

Trade settlement is **derived** from the positions ledger, not mirrored into synthetic
`cash_movements` rows. Editing or deleting a position therefore corrects the cash by itself: there
is no second copy to keep in step. `cash_movements` keeps its own job — money that moves for reasons
other than a trade.

All of the arithmetic lives in `PositionPricing::signedCashFlow()`. The calculator sums it and the
Stockbit importer subtracts it; deriving it separately in two places would let them drift.

### Migrating existing balances

Stored `cash_balance` values were entered and reconciled under the old formula — typically "what the
broker says I have right now", with every historical trade already reflected. Switching formulas
without touching them would subtract every historical purchase a second time.

`2026_08_31_000100_rebase_portfolio_cash_for_trade_settlement` rebases the stored base by exactly
the term being introduced:

```
new_base = old_base - historical_signed_trade_cash_flow
```

so the two formulas agree the instant the migration finishes:

```
  new_base + movements + trade_flow
= (old_base - trade_flow) + movements + trade_flow
= old_base + movements                              ← what was on screen a moment ago
```

**Displayed cash does not move.** From then on the term is live: the next trade moves the cash, and
the base means what it says. A `cash_accounting_version` column guards against a second application,
which would be silent and would look exactly like a large unexplained withdrawal.

### Stockbit snapshot reconciliation

Because available cash is now three terms, the base that reproduces a broker's reported figure is
that figure minus **both** other terms:

```
proposed_base = broker_reported_cash - non_trade_movements - trade_settlements
```

Subtracting only the movements, as the importer did while cash was `base + movements`, would leave
every imported buy and sell counted twice — once inside the broker's figure, which already reflects
them, and once again when the calculator adds the trade term.

### Long-only

A manual exit larger than the holding it claims to close is rejected. The calculator matches with
`min(exit_qty, holding_qty)`, which prevents a negative position but does so silently: the surplus
shares vanish from the realized P/L and the ledger goes on looking healthy. Imported broker history
is exempt — it is a record of real executions, reconciled through the importer's opening positions
rather than by rejecting it.

## Accumulation Anomaly Scanner
Use this command to scan for potential accumulation setups using your idea: daily price down on lower volume, lower-timeframe absorption proxy (PBAS/absorption flag), and anchored broker-flow confirmation.

```bash
php artisan strategy:scan-accumulation --date=2026-04-08 --anchor-date=2026-01-01 --max-ret=-0.005 --max-vol-ratio=0.95 --min-pbas=70 --min-anchor-net-norm=0.01
```

Optional filters:
- `--symbol=BBCA --symbol=ANTM` (or comma-separated) to scan only selected tickers.
- `--limit=50` to expand result count.
- `--min-net-norm` to tighten same-day absorption proxy from broker net-flow.

## Data resilience: the three layers

Losing the database has always been survivable — every bar and every broker summary came from a
file, and the files are kept. What it was not, before this, was *quick*: recovery meant replaying
thousands of scattered raw payloads through the importers and hoping the result matched. The
reconciliation layer sits between the raw files and the database so a rebuild is a read, not a
reprocessing run.

| Layer | What it is | Authority |
| --- | --- | --- |
| **Raw archive** (`broker_summary/*.json`, `{SYMBOL}.csv`) | Every response exactly as it arrived | **Source of record.** Never deleted, never rewritten, never consumed. |
| **Reconciliation** (`reconciliation/`) | One JSON document per asset, plus a manifest | **Canonical recovery copy.** Derived from the database, restorable back into it. |
| **Database** (`price_bars`, `broker_summary_windows`, `features_daily`, …) | The query layer | **Working state.** Rebuildable from the layer above. |

The direction only ever runs one way: raw → reconciliation → database, and `data:restore` walks
it back. Reconciliation reads the raw files, it does not consume them; restoring never removes
them; and the whole `reconciliation/` directory can be deleted and rebuilt from the database in
one command. Nothing in it is the last copy of anything.

Google Drive stays what it was — cold storage. The reconciliation documents are mirrored there,
but the mirror is a copy of a derived layer, not the working database.

### Layout

```
storage/app/
  broker_summary/                          # raw archive, untouched by this feature
    BBCA_2026-08-28_2026-08-28_TRANSACTION_TYPE_NET.json
  reconciliation/
    manifest.json                          # the index: one row per asset
    assets/
      BBCA.json                            # one document per asset
      BBRI.json
```

One file per asset, not one enormous file. A universe of four hundred symbols means four hundred
small documents and one manifest, so a nightly run rewrites and re-uploads only what changed, and
a single corrupt document costs one asset rather than everything.

### An asset document

Abbreviated — the real file carries every stored bar and every broker entry:

```json
{
  "schema_version": 1,
  "symbol": "BBCA",
  "generated_at": "2026-09-02T18:40:11+07:00",
  "as_of_trading_date": "2026-09-01",
  "source_fingerprint": "3f9c…",
  "asset": { "symbol": "BBCA", "name": "Bank Central Asia", "sector": "Financials",
             "sync_price": true, "sync_broker_summary": true },
  "coverage": {
    "ohlcv": { "first_date": "2011-01-03", "last_date": "2026-09-01", "rows": 3821,
               "source_path": "/var/www/breakout-data/historical/BBCA.csv",
               "source_exists": true, "source_hash": "b21e…", "source_size": 184213 },
    "broker_summary": { "first_window_from": "2026-05-04", "last_window_to": "2026-09-01",
                        "window_count": 84, "single_day_window_count": 81,
                        "aggregate_window_count": 3, "latest_single_day": "2026-09-01",
                        "daily_flow_sessions": 81 }
  },
  "integrity": {
    "status": "healthy",
    "warnings": [], "errors": [],
    "missing_broker_sessions": [], "missing_broker_session_count": 0,
    "duplicate_ohlcv_dates": [], "invalid_broker_ranges": [],
    "duplicate_broker_windows": [], "missing_source_files": [],
    "broker_lag_sessions": 0
  },
  "ohlcv": [
    { "date": "2026-09-01", "open": 8000, "high": 8100, "low": 7950, "close": 8075, "volume": 91230400 }
  ],
  "broker_summary": {
    "windows": [
      { "from_date": "2026-09-01", "to_date": "2026-09-01", "is_single_day": true,
        "transaction_type": "TRANSACTION_TYPE_NET",
        "source_filename": "broker_summary/BBCA_2026-09-01_2026-09-01_TRANSACTION_TYPE_NET.json",
        "source_hash": "9ab4…",
        "bandar_detector": { "broker_accdist": "Accumulation", "accdist_score": 1, "…": "…" },
        "entries": [ { "broker_code": "BK", "…": "…" } ] }
    ],
    "daily_flow": [
      { "date": "2026-09-01", "broker_accdist": "Accumulation", "accdist_score": 1,
        "turnover_value": 512300000000, "average_price": 8043.2 }
    ]
  },
  "insight": {
    "latest_broker_date": "2026-09-01", "latest_accdist": "Accumulation",
    "latest_accdist_score": 1, "daily_sessions_total": 81,
    "flow_balance_5d": 3, "available_daily_sessions_5d": 5, "price_return_5d": 0.0142,
    "flow_balance_20d": 6, "available_daily_sessions_20d": 20, "price_return_20d": -0.0038
  }
}
```

Two things in there are load-bearing.

`is_single_day` is **stored**, not derived on read, so no consumer can reach a different answer by
comparing the dates its own way. `daily_flow` contains only windows where it is true: a range
aggregate covers its whole window and is never split into the days inside it. Every flow number
on the dashboard is computed from `daily_flow`, so an aggregate can never masquerade as a
session.

Every `flow_balance_Nd` travels with `available_daily_sessions_Nd`. "+3 over three available
sessions" and "+3 over twenty" are different statements, and a reader given only the balance
cannot tell them apart — so an asset without a full window is reported as *insufficient* rather
than as neutral.

### The manifest

```json
{
  "schema_version": 1,
  "generated_at": "2026-09-02T18:40:12+07:00",
  "market_date": "2026-09-01",
  "summary": { "asset_count": 412, "healthy": 401, "warning": 9, "error": 2,
               "with_gaps": 7, "ohlcv_current": 408, "broker_current": 396,
               "latest_ohlcv_date": "2026-09-01", "latest_broker_daily_date": "2026-09-01" },
  "assets": {
    "BBCA": { "path": "reconciliation/assets/BBCA.json", "hash": "d41d…", "size": 812443,
              "source_fingerprint": "3f9c…", "ohlcv_last": "2026-09-01", "ohlcv_rows": 3821,
              "latest_broker_daily": "2026-09-01", "integrity_status": "healthy",
              "gap_count": 0, "broker_lag_sessions": 0,
              "flow_balance_5d": 3, "available_daily_sessions_5d": 5 }
  }
}
```

The manifest is what the dashboard reads. Everything the asset table shows is in a row, so
listing four hundred assets is one file read rather than four hundred.

### Rebuilding, and only what changed

```bash
php artisan data:reconcile --all              # rebuild what changed, write the manifest
php artisan data:reconcile --symbol=BBCA      # one asset
php artisan data:reconcile --all --dry-run    # report what would change, write nothing
php artisan data:reconcile --all --verify     # re-read each document and check it
php artisan data:reconcile --all --mirror     # and push to cold storage
```

Idempotence is the property the whole design rests on: running this twice with no new market data
must rewrite nothing and upload nothing, or the nightly mirror re-uploads the universe every
night and the incremental design buys nothing. Two independent mechanisms enforce it. A
**fingerprint** per asset — bar count, first and last date, close sum, volume sum, each window's
stored `source_hash`, and the seed CSV's size and mtime — decides whether to rebuild at all; the
sums are there so a *corrected* close, which leaves the date extent untouched, still changes the
fingerprint. And the store refuses to rewrite a file whose bytes already match, so even a forced
rebuild that produces identical output leaves the hash and mtime where they were.

JSON is serialised deterministically (no pretty-printing, keys in insertion order, unescaped
slashes and unicode) so that byte comparison is meaningful in the first place.

### Restoring

```bash
php artisan data:restore --all                # rebuild the database from the local layer
php artisan data:restore --symbol=BBCA
php artisan data:restore --all --disk=gdrive  # straight from cold storage
php artisan data:restore --all --dry-run
php artisan data:restore --all --skip-csv     # database only, leave the seed CSVs alone
```

Every document is validated before anything is written: the schema version must be one this
build understands, the symbol inside must match the file it came from, the required sections must
be present, and the content must hash to what the manifest recorded. A document that fails costs
that asset and no other — the restore reports it and carries on rather than leaving the database
half-rebuilt from a file it could not read.

Restore is idempotent and non-destructive. Bars and windows are keyed the same way the importers
key them, so running it twice converges; a range aggregate is restored as the aggregate it is;
and the seed CSVs are rewritten through the same `CsvBars` writer the rest of the pipeline uses.

### Nightly

`automation:data-reconciliation` runs between collection and analysis — after the day's broker
summaries land, before the derived pipelines read them — at priority 25 in the seeded schedule:

```bash
php artisan automation:data-reconciliation                 # what the scheduler runs
php artisan automation:data-reconciliation --force --verify
php artisan automation:data-reconciliation --no-mirror
```

The run record carries the assets checked, changed, skipped and failed, the manifest hash, and
the mirror outcome. A mirror failure is reported as **degraded**: the local layer is complete and
correct, and only the off-server copy is behind.

### The mirror's commit order

Documents are uploaded and each one verified by reading it back, and only then is the manifest
uploaded. The manifest is the commit marker, so a manifest present in cold storage always
describes documents that are already there. If any asset upload fails, the manifest is
**withheld** and the previous one stays standing — a torn set is left looking like the older
complete set rather than like a newer broken one.

Nothing is ever called synchronised on the strength of a filename. The published manifest is
compared by hash, and a remote that could not be reached is reported as unknown.

### The dashboard

`/dashboard/backups` opens on the question that matters at 3am — *can I rebuild, and is the
off-server copy current?* — which is answered from the manifest and one remote hash, in a handful
of reads that do not grow with the archive.

The file-by-file comparison still exists and still catches a Drive copy that was silently
truncated or edited. It moved behind an explicit **File-by-file audit** action, because it costs
one metadata call per archived file and paying that on every page load meant the page got slower
every day it was used.

| Endpoint | Cost | Answers |
| --- | --- | --- |
| `GET /v1/backup-status` | three reads | Readiness, manifest health, mirror state, flow snapshot |
| `GET /v1/backup-status?deep=1` | grows with the archive | Both, in one request |
| `GET /v1/backup-status/audit` | grows with the archive | Does every raw file match its Drive copy? |
| `POST /v1/backup-status/mirror-push` | — | Repair the historical CSVs or the raw archive on Drive |
| `GET /v1/reconciliation` | one read | The asset table: filter, sort, page — all from the manifest |
| `GET /v1/reconciliation/{symbol}` | one read | One asset's coverage, integrity and recent trajectory |

Readiness is deliberately conservative. **Ready** requires a manifest that exists, describes
assets, carries no errors, *and* matches what cold storage holds — any one of those failing is a
recovery that would not complete, and the page names which.

The push endpoints take symbols, never paths, and intersect them with what the server itself
found; the raw-archive push enumerates the archive server-side and ignores anything the browser
sends.

### Configuration

```dotenv
RECONCILIATION_PATH=reconciliation          # directory on the local and remote disks
RECONCILIATION_LOCAL_DISK=local
RECONCILIATION_MIRROR_DISK=                 # falls back to CSV_MIRROR_DISK; empty disables the mirror
RECONCILIATION_BROKER_LAG_WARNING=2         # sessions of broker lag before a warning
RECONCILIATION_BROKER_LAG_ERROR=5           # …and before an error
RECONCILIATION_MISSING_SESSION_LOOKBACK=30  # how far back gaps are looked for
RECONCILIATION_CHUNK_SIZE=25                # assets per chunk; documents are still built one at a time
```

Every one has a working default. A deployment that already configured `CSV_MIRROR_DISK` gets
reconciliation mirroring without setting anything new.

### Directory permissions

The CLI and the web process are different users: artisan runs as the deploy user, PHP-FPM as
`www-data`. Flysystem creates a "private" directory as **0700**, so every directory the scheduler
created was one the API could not traverse — and the failure is silent, because
`Storage::exists()` simply returns `false`. A fully built reconciliation layer reported itself as
*"not built yet"* on that basis, while cold storage held a published copy of it.

`config/filesystems.php` now sets 0775/0664 on the `local` disk, matching `storage/app/private`
itself, which is already `deploy:www-data` and group-writable. That fixes directories created from
now on. **Directories created before it need a one-time fix:**

```bash
chmod -R g+rX storage/app/private
# confirm the web process can traverse it
sudo -u www-data test -r storage/app/private/reconciliation/manifest.json && echo readable
```

### Deploying this feature

```bash
# 1. Pull and install as usual, then run the migration that seeds the schedule.
php artisan migrate --force

# 2. Build the layer for the first time. On a full universe this takes a few
#    minutes and is safe to run while the app serves traffic; it only reads.
php artisan data:reconcile --all

# 3. Publish it to cold storage and confirm the manifest matches.
php artisan data:reconcile --all --mirror
php artisan data:reconcile --all --verify

# 4. Confirm the dashboard agrees.
#    /dashboard/backups should report "Recovery ready".
```

The nightly `daily-data-reconciliation` task is seeded enabled. Nothing else needs enabling, and
no existing raw file is moved, renamed or reorganised by any of this.

To rehearse a recovery, restore into a scratch database rather than the live one:

```bash
php artisan data:restore --all --dry-run     # what would be written
php artisan data:restore --symbol=BBCA       # one asset, for real
```

## Storage Backends

Breakout keeps two different kinds of state, and they are stored differently on purpose.

| Layer | What lives there | Where it lives |
| --- | --- | --- |
| **Database** (`price_bars`, `features_daily`) | Every bar and feature the app queries | Always the relational DB. **This is the query layer and the source of truth.** |
| **File artifacts** (Stockbit JSON payloads, OHLCV seed CSVs) | Raw scrape payloads and the seed/export CSVs | Local disk by default; optionally mirrored to durable cold storage. |

Google Drive is **backup and cold storage only**. Nothing reads it to answer a query — it has no random access, no atomic rename, and a request quota, none of which suit a hot path. Moving a file artifact to Drive never moves a query off the database.

Everything below is opt-in. With the shipped defaults (`SB_SAVE_DISK=local`, `CSV_MIRROR_DISK` unset) the pipeline is entirely local and makes no remote calls, which is the intended development setup.

### The two file paths

**JSON payloads** (`broker_summary/`, `historical/`, `watchlist_eod/`) already write through `Storage::disk()`, so they follow a single setting:

```dotenv
SB_SAVE_DISK=gdrive   # local (default) | gdrive | s3
```

**OHLCV seed CSVs** (`{SYMBOL}.csv`) are built on local disk and *mirrored* to the durable disk in batch:

```dotenv
CSV_SEED_DIR=            # empty uses database/seeders/data/historical
CSV_MIRROR_DISK=gdrive   # empty (default) disables mirroring entirely
CSV_MIRROR_PATH=seeds/historical
```

> **On a server, set `CSV_SEED_DIR` to a path outside the working tree.**
>
> The default lives in `database/seeders/data/historical`, which is git-tracked
> — right for the committed bootstrap data, wrong for accumulating market data.
> The deploy runs `git reset --hard` in a persistent checkout, so every deploy
> rewrites those files back to whatever was committed and discards every bar the
> scheduler appended since. Point it somewhere the deploy cannot reach:
>
> ```dotenv
> CSV_SEED_DIR=/var/www/breakout-data/historical
> ```
>
> Moving an existing installation is a copy and a config cache rebuild:
>
> ```bash
> mkdir -p /var/www/breakout-data/historical
> cp database/seeders/data/historical/*.csv /var/www/breakout-data/historical/
> # add CSV_SEED_DIR to .env, then
> php artisan config:cache
> php artisan bars:mirror-push --disk=gdrive   # confirm local and Drive agree
> ```
>
> The committed CSVs stay where they are as bootstrap data for a fresh checkout.

They are not written straight to Drive. The CSV flow is read-existing → merge → write-back, looped per symbol and per date chunk; doing that against Drive would cost an API call per iteration, invite `403 rateLimitExceeded`, and lose the atomic temp-file + `rename()` that makes a half-written CSV impossible. Instead each data-mutating command hydrates the local CSVs from the mirror before its loop and pushes the changed ones after it:

```
hydrate  →  existing local read/merge/write loop (unchanged)  →  flush
```

`stockbit:scrape`, `asset:sync`, `ohlcv:check`, and `csv:fix-date-format` all accept `--disk=` to override `CSV_MIRROR_DISK` for a single run. Passing `--disk=` with an empty value forces a purely local run.

### Setting up Google Drive OAuth for a personal Gmail account

Authentication is OAuth 2.0 as a real Google user, not a service account. A service account cannot be used here: Google gives them no storage quota, so one can create folders in My Drive quite happily and then fail on the first actual file, which makes the failure look like a permissions problem when it is not.

1. In the [Google Cloud console](https://console.cloud.google.com/), create (or pick) a project and **enable the Google Drive API**.
2. Configure the **OAuth consent screen**. Add the Gmail account that will own the files as a test user if the app stays in Testing.
3. Create an **OAuth 2.0 Client ID** of type **Web application**.
4. Add `https://developers.google.com/oauthplayground` as an **authorized redirect URI** — this is what lets the Playground mint the refresh token below.
5. Open the [OAuth 2.0 Playground](https://developers.google.com/oauthplayground/), and in the gear menu tick **Use your own OAuth credentials**, pasting the client ID and secret.
6. In step 1 enter the scope `https://www.googleapis.com/auth/drive` — the full Drive scope. `drive.file` and the read-only scopes are not enough for the mirror.
7. Authorize as the Gmail account that should own the backups, then **Exchange authorization code for tokens**.
8. Copy the **refresh token**. That is the credential the VPS uses; it lets scheduled and CLI jobs authenticate with no browser.
9. Fill in `apps/api/.env` on the server:

   ```dotenv
   GOOGLE_DRIVE_CLIENT_ID=xxxx.apps.googleusercontent.com
   GOOGLE_DRIVE_CLIENT_SECRET=xxxx
   GOOGLE_DRIVE_REFRESH_TOKEN=xxxx

   GOOGLE_DRIVE_FOLDER_ID=              # blank = My Drive root
   GOOGLE_DRIVE_ROOT=breakout-data

   CSV_MIRROR_DISK=gdrive
   CSV_MIRROR_PATH=seeds/historical
   ```

   Those values are placeholders. `GOOGLE_DRIVE_CLIENT_SECRET` and `GOOGLE_DRIVE_REFRESH_TOKEN` are credentials — never commit them, and there is no longer any JSON key file to protect.

   Leaving `GOOGLE_DRIVE_FOLDER_ID` blank puts everything under `My Drive/breakout-data`. Set it only to nest the app folder inside an existing folder, and then it must hold just the id from that folder's URL.

10. **While the consent screen is in Testing, refresh tokens expire after seven days.** For a VPS that should keep working, publish the app in the Google Cloud console. This is the single most common cause of Drive working for a week and then failing with `invalid_grant`.

11. Apply the configuration and verify:

    ```bash
    cd apps/api
    php artisan optimize:clear
    php artisan config:cache      # config:cache bakes .env in; clearing first is required
    php artisan gdrive:check
    ```

    `gdrive:check` reports each credential as `set` or `unset` — never their values — then writes, reads back, overwrites and deletes a probe file, naming whichever step fails. `--keep` leaves the probe in place so you can see it in Drive.

### Migrating existing CSVs to the mirror

```bash
php artisan bars:mirror-push --disk=gdrive     # upload every local seed CSV
php artisan bars:mirror-push --disk=gdrive --symbol=BBCA --symbol=BBRI
php artisan bars:mirror-push --disk=gdrive --force   # re-upload even if unchanged
```

`bars:mirror-push` reports local and remote CSV counts and exits non-zero if any local CSV is missing from the mirror. **Compare those counts before treating the local copy as expendable.**

The inverse restores a machine (or a clean checkout) from the mirror:

```bash
php artisan bars:mirror-pull --disk=gdrive
php artisan bars:mirror-pull --disk=gdrive --force   # overwrite newer local files
```

By default a pull only fills in CSVs that are missing or older locally, so it will not clobber work in progress.

### Notes

- Repeat runs are cheap: a flush uploads only CSVs whose contents actually changed, tracked by a local hash manifest at `storage/app/bar-csv-mirror.json`. Delete that file (or use `--force`) to force a full re-upload.
- When a local CSV has diverged from what was last mirrored, **bar coverage decides which copy wins, not modification time**. A `git reset --hard` rewrites a tracked CSV with a fresh mtime, so a file that had just lost a month of bars looked *newer* than the Drive copy still holding them — hydrate stood down and the following flush pushed the truncated file over the good backup. The copy with more rows now wins in both directions, so a run that extended a CSV and died before flushing also keeps its extra bars. `--force` still overrides for disaster recovery.
- Mirror failures are logged and skipped, never fatal. By the time the flush runs, the database rows and local CSVs — the real output of a run — are already written.
- Throttling (`403 rateLimitExceeded`) and transient errors are retried with exponential backoff.
- The Stockbit bearer token store stays on its own local disk and is deliberately **not** routed to Drive.
- For a data pipeline, an S3-compatible store (Cloudflare R2, Backblaze B2) is technically a better fit than Drive, and `config/filesystems.php` already ships an `s3` disk. Because both go through the same Flysystem interface, switching is just `CSV_MIRROR_DISK=s3` / `SB_SAVE_DISK=s3`.

## Automation & Scheduling

Scheduled market-data work lives in the database, not in `routes/console.php`. Automations
can be created, edited, enabled, disabled, deleted and run on demand from
**`/dashboard/automation`**, and take effect on the next minute — no deploy required.

### Timezone

Every schedule and every market-day calculation is evaluated in **`Asia/Jakarta` (WIB, UTC+7)**,
because that is the market this system follows.

`config/app.php` still says `UTC`, and that is deliberate: timestamps are *stored* in UTC and the
application's global timezone is not changed to make the scheduler work. What is Jakarta is the
*interpretation* — `0 16 * * *` on a task whose timezone is `Asia/Jakarta` fires at 16:00 WIB
(09:00 UTC) regardless of what the server clock is set to, and "today" for a trading-day
condition is the Jakarta date, not the server's.

Override with `AUTOMATION_TIMEZONE` if you ever need to; each task also carries its own
`timezone` column.

### Architecture

```
cron (every minute)
  └─ php artisan schedule:run
       └─ scheduler:dispatch                 ← the ONE static entry in routes/console.php
            ├─ reads enabled rows from `scheduled_tasks`, in priority order
            ├─ works out which occurrences are due, in each task's own timezone
            ├─ evaluates the task's market condition against `trading_calendar`
            ├─ takes a per-task lock, and a shared lock for bulk Stockbit jobs
            └─ Artisan::call(<allowlisted command>, <structured parameters>)
                 └─ writes a `scheduled_task_runs` row: status, timings, output, metadata
```

The market-day conditions read `trading_calendar`, and the seeded
`automation:trading-calendar-refresh` task is what keeps that table current — it runs earliest
and at the front of the priority order, so the jobs that depend on it always see a fresh
calendar. See *Keeping the trading calendar current* below.

There is exactly one static scheduler entry. A second scheduling mechanism alongside the
database one would mean two places to look when a job does not run.

The previous hard-coded `asset:sync --eod` at 15:00 `Asia/Qatar` was **removed** — it competed
with the database-managed daily OHLCV sync, which runs at 16:00 WIB and only on days the IDX
actually traded.

### Required production setup

Cron must invoke Laravel's scheduler every minute. Add this to the deploy user's crontab:

```cron
* * * * * cd /var/www/breakout/apps/api && php artisan schedule:run >> /dev/null 2>&1
```

`scheduler:dispatch` runs due tasks in-process, in priority order, so a bulk scrape holds the
tick until it finishes. It is registered `withoutOverlapping()->runInBackground()`, so the next
minute's `schedule:run` returns immediately rather than queuing up behind it.

**"Run now" from the dashboard is queued**, so a queue worker must be running for that button to
do anything (scheduled runs do not need one):

```ini
; /etc/supervisor/conf.d/breakout-worker.conf
[program:breakout-worker]
command=php /var/www/breakout/apps/api/artisan queue:work --sleep=3 --tries=1 --timeout=7200
directory=/var/www/breakout/apps/api
user=www-data
autostart=true
autorestart=true
stopwaitsecs=7300
redirect_stderr=true
stdout_logfile=/var/log/breakout-worker.log
```

`--tries=1` is intentional: the runner records the outcome on the run row, so a retry would
re-execute an hour of API calls that already reported how they went.

### Default seeded automations

Installed by the migrations that create the tables, and restorable with
`php artisan db:seed --class=AutomationSeeder`. All six are fully editable from the dashboard.

| Name | Schedule | Condition | Priority | Command |
| --- | --- | --- | --- | --- |
| Trading Calendar Refresh | 17:30 `Asia/Jakarta`, daily | `none` | 1 | `automation:trading-calendar-refresh` |
| Stockbit Token Reminder | 09:00 `Asia/Jakarta`, daily | `none` | 5 | `automation:token-check` |
| Daily OHLCV Sync | 18:00 `Asia/Jakarta`, daily | `trading_day` | 10 | `automation:ohlcv-daily` |
| Daily Broker Summary | 18:00 `Asia/Jakarta`, daily | `trading_day` | 20 | `automation:broker-summary-daily` |
| Daily Data Reconciliation | 18:00 `Asia/Jakarta`, daily | `none` | 25 | `automation:data-reconciliation` |
| Daily Analysis Refresh | 18:00 `Asia/Jakarta`, daily | `none` | 30 | `automation:analysis-refresh` |

In plain language:

- **Every day at 17:30 WIB** → refresh the trading calendar, so the conditions below have
  something current to read. This runs first, on holidays too — a holiday is precisely when the
  calendar needs to record that it was one.
- **Every valid IDX trading day at 18:00 WIB** → update OHLCV for every asset with
  `sync_price = true`, then bring every asset with `sync_broker_summary = true` up to the latest
  valid trading day. The two scrapes never run at the same time: priority orders them and both
  take a shared Stockbit lock, so the broker summary queues behind the OHLCV sync.
- **Then, still in the same pass** → rebuild the reconciliation documents for whatever changed
  and publish them to cold storage. Priority 25 puts it after the day's collection and before the
  derived pipelines, so the recovery copy describes the data the analysis is about to read. See
  [Data resilience](#data-resilience-the-three-layers).
- **Then, in the same pass** → recompute everything derived from what just landed:
  `features_daily`, asset metrics, broker accumulation rollups, watchlist scores and the saved
  rule-builder strategy runs. Priority 30 puts it last, so it always sees the day's imports.
- **After successful persistence** → mirror the corresponding files to Google Drive.
- **Every day at 09:00 WIB** → inspect the Stockbit token and warn when renewal is needed.

The four 18:00 jobs run **in one dispatcher pass, in priority order, in the same process** —
that is what makes "after" reliable. A separate later cron time would not: `scheduler:dispatch`
is registered `withoutOverlapping()`, so a later occurrence arriving while the scrapes are still
running would be dropped rather than queued.

**Why the analysis refresh has no market-day condition.** It reads only what is already stored
and costs no API quota, and it is a no-op when nothing has changed. On a day the calendar cannot
confirm — when the two scrapes skip — it still picks up data that arrived late, instead of
skipping alongside them.

**Why 18:00 and not 16:00.** The calendar is derived from Yahoo's published `^JKSE` bars, so it
cannot confirm that today traded until that bar exists. 16:00 WIB is the closing bell itself,
which is too early for that to be true.

### Daily broker summaries and backfill

`automation:broker-summary-daily` resolves its range **per asset**, not globally, and every
window it creates covers exactly one trading session:

```
from = the first trading session after that asset's newest stored single-day window
to   = the latest day `trading_calendar` records as having actually traded
```

**Each session is fetched as its own window.** That is the rule the daily pipeline rests on, and
it is a change from how this job first worked. It used to repair a multi-day gap with one
aggregate covering the whole of it — cheaper, and a perfectly valid archive record, but not the
same thing. Monday's flow, Tuesday's flow and Wednesday's flow are three observations; their sum
over Monday to Wednesday is one. A range aggregate cannot be taken apart into the path through
it, so an aggregate filed where daily observations belong silently destroys the
accumulation/distribution trajectory it looks like it provides.

Tickers missing the same session are grouped into one scrape invocation, so the number of calls
tracks the number of *dates*, not tickers × dates: four hundred tickers missing three sessions is
three scrapes, not twelve hundred. An asset already current is skipped without an API call.

Two bounds keep a first run sane:

- `--max-backfill-sessions` (default 5) caps how many sessions one run collects per ticker. The
  most recent are taken first, so an asset keeps moving forward every night rather than crawling
  out of a long gap oldest-first.
- An asset with **no** genuine daily history is not given months of it by a nightly job. It is
  given a cursor — the latest confirmed session — and grows a daily series forward from there.
  Establishing history backwards is an explicit, bounded operation via `--from`, because it is an
  API budget decision rather than routine maintenance.

Existing multi-day aggregates are untouched and stay valid. They are real archive records and
good evidence at their own length; the database legitimately holds both, and only windows where
`from_date === to_date` are ever treated as daily observations. Nothing fabricates individual
days from an aggregate.

`to` is deliberately the last *observed* trading day and not today: on a trading evening before
Yahoo publishes, the calendar still ends yesterday, and fetching through today anyway would file
a partial session as a complete one. The lag is reported as `days_behind` and closes itself on
the next run.

Overlapping ranges are safe by construction. A window is keyed on
`(asset, from_date, to_date, transaction_type)`, so a legacy backfill aggregate and the daily
sessions inside it are separate rows that never overwrite each other, and re-running a session
converges instead of duplicating. `BrokerWindowResolver` never sums overlapping windows into one
rollup.

The run record reports `sessions` (each date with the ticker count collected for it),
`session_count`, and — for assets that had no daily history at all —
`cursor_established_count` and `cursor_established_tickers`.

`automation:broker-summary-weekly` still exists and still works if you want a week on purpose,
but it is no longer part of the seeded schedule — the migration disables the seeded row rather
than deleting it, so its run history survives.

```bash
php artisan automation:broker-summary-daily                            # what the scheduler runs
php artisan automation:broker-summary-daily --tickers=BBCA --tickers=BRPT
php artisan automation:broker-summary-daily --from=2026-01-01          # force a start for every ticker
php artisan automation:broker-summary-daily --max-backfill-sessions=10 # a bigger catch-up slice
php artisan automation:broker-summary-daily --no-import --no-mirror
```

### What the analysis refresh rebuilds

`automation:analysis-refresh` runs five steps in dependency order, each individually skippable:

| Step | Command | Reads | Writes |
| --- | --- | --- | --- |
| features | `features:extract` | prices, broker windows | `features_daily` |
| metrics | `asset:metrics --all --persist` | prices, `features_daily` | `metrics` |
| rollup | `strategy:rollup-broker-accumulation` | broker windows | `broker_accumulation_windows` |
| watchlist | `strategy:rank-watchlist` | `features_daily`, `metrics`, rollups | `watchlist_scores` |
| strategies | `strategy:run` | `features_daily` | `strategy_runs` |

It also catches up rather than recomputing only today: it re-extracts from the newest date
features already exist for through the newest date a price bar exists for, bounded by
`--max-days` (default 10). The newest computed day is deliberately rebuilt too — the broker
summary for a day is imported after the bars, so that day's features are the ones most likely to
have been built from incomplete inputs.

The scan date follows the **data**, not the clock: it is the newest date a price bar exists for.
Claiming today when the scrape has not landed would date every derived row wrongly.

A failing step is recorded on the run and the rest still run. These outputs are independent
enough that abandoning the remaining four because the first struggled would leave more of the
dashboard stale, not less. Every step is an upsert, so running it twice changes nothing.

```bash
php artisan automation:analysis-refresh                      # what the scheduler runs
php artisan automation:analysis-refresh --date=2026-08-28    # one specific day
php artisan automation:analysis-refresh --max-days=30        # widen the catch-up window
php artisan automation:analysis-refresh --symbol=BBCA        # one ticker through every step
php artisan automation:analysis-refresh --skip-strategies    # any step can be left out
```

### Keeping the trading calendar current

Every market-day condition reads `trading_calendar`, and nothing else advances it. Because a
missing row means *unknown* rather than *closed* (see below), a calendar that stops advancing
does not fail loudly — it makes every dependent automation skip, quietly, forever. That is what
`automation:trading-calendar-refresh` exists to prevent.

Each run imports recent trading days from Yahoo, then rebuilds the calendar. It holds one rule:

> Never write a calendar row for a date beyond the last day Yahoo actually has a bar for.

`TradingCalendarBuilder` decides by absence — a weekday with no `trading_days` row becomes
`is_holiday = true`. That is correct for a date the market has already traded past, and wrong
for one it has not reached yet. Rebuilding through "today" before today's bar is published would
positively record today as a holiday, and the conditions would then skip with a confident
`not_trading_day` instead of an honest `trading_calendar_incomplete`. So the range is clamped to
the last observed trading date and never guesses past it.

The consequence is that the calendar always trails the market by however long Yahoo takes to
publish. That lag is reported as `days_behind` on every run rather than hidden, and a lag beyond
five days marks the run partial — it means the import has been failing and every market-day
condition is skipping as a result.

```bash
php artisan automation:trading-calendar-refresh                  # what the scheduler runs
php artisan automation:trading-calendar-refresh --lookback=365   # widen the rebuild window
php artisan automation:trading-calendar-refresh --skip-import    # rebuild without calling Yahoo
```

A failed Yahoo import is reported but not fatal: the calendar is still rebuilt from the trading
days already stored, because losing that because the network was down would be the worse outcome.

For a first run, or after a long outage, seed the history directly:

```bash
php artisan trading-days:build --from=2015-01-01 --to=$(date +%F)
php artisan trading-calendar:build --from=2015-01-01 --to=$(date +%F)
```

### How trading-day conditions work

Conditions are answered from the `trading_calendar` table, never from the shape of the week.

- **`none`** — runs on every occurrence.
- **`trading_day`** — resolves today in `Asia/Jakarta` and runs only if that date's row exists
  and `is_trading_day = true`.
- **`last_trading_day_of_week`** — takes the Monday–Sunday week containing today, lists the
  dates in it with `is_trading_day = true`, and runs when today equals the last of them. The
  first and last of those dates become the weekly scrape's `--from` and `--to`.

A Monday holiday moves `from` to Tuesday; a Friday holiday moves `to` to Thursday. Neither is
assumed — both come from the calendar.

**Missing rows are not treated as holidays.** A date with no row is *unknown*, and to a plain
query that is indistinguishable from "closed" — which would make Thursday look like the week's
final trading day every time the calendar fell behind, and file a Monday–Thursday range as the
week's summary. So an incomplete week produces a skipped run with reason
`trading_calendar_incomplete`, surfaced in the Automation history and on the status header. A
week containing no trading day at all is skipped as `no_trading_days_in_week`.

#### The weekly catch-up

`last_trading_day_of_week` is no longer used by a seeded automation — the broker summary is
collected daily now — but the condition remains available, and this is how it behaves.

That honesty has a cost, and the catch-up is what pays it.

When Friday is a holiday, Thursday is the week's last trading day — but standing on Thursday the
calendar has no row for Friday yet, so it cannot know that, and correctly refuses to guess. Left
there, that week would never be summarised at all.

So `last_trading_day_of_week` also passes on the **first trading day of a new week**, when the
previous week is by then fully settled. The weekly job then summarises that previous week
retrospectively, using its true `from`..`to`. Nothing predicts the future: both cases are decided
from rows that already exist.

Two guards keep this from misfiring:

- Only the week's *opening* trading day catches up, so a Wednesday does not keep re-proposing a
  week Monday already handled.
- Before scraping, the job checks whether a `BrokerSummaryWindow` already exists for that exact
  range. If it does, the run records `week_already_summarised` and costs one query. Asking the
  canonical record rather than the run history means this survives the task being renamed,
  recreated, or run by hand.

The normal Friday path is untouched, and re-running it deliberately is still supported: the
importer is idempotent, so it repairs a bad import rather than duplicating one.

### Safe execution — no shell, no remote command injection

The dashboard can schedule commands, but it can never describe a command *line*. A
`scheduled_tasks` row stores an Artisan command **name** from the allowlist in
`config/automation.php` plus a structured `{arguments, options}` map, and both are validated on
write and again before execution. Execution goes through `Artisan::call()`; nothing is
concatenated, quoted, or handed to a shell — there is no `exec()`, `shell_exec()`, `proc_open()`
or `Process` anywhere on this path.

Each allowlisted command declares its acceptable parameters and their types (`boolean`,
`integer`, `date`, `enum`, `string` with a pattern, `symbol_list`). Anything undeclared, or a
value of the wrong shape, is a 422.

**The Stockbit bearer can never be stored as a task parameter.** `stockbit:scrape` is
allowlisted *without* `--token`, and a name-level blocklist (`token`, `bearer`, `secret`,
`password`, `api-key`, …) rejects it regardless of what the config says. The token is resolved at
execution time from the existing encrypted store.

To allow another command, add it to `config/automation.php` under `commands`.

### Stockbit token lifecycle

The existing token system remains the source of truth: `StockbitTokenResolver`,
`StockbitTokenStore` (encrypted, on the local disk, never mirrored to Drive),
`StockbitExodusClient::jwtExpiresAt()`, `stockbit:token:set` and `stockbit:token:status`. No
second plaintext field was added anywhere, and no API returns the bearer.

Every scheduled bulk Stockbit job **preflights the token before it starts**, and reports one of:

| State | Meaning |
| --- | --- |
| `healthy` | Present, and comfortably above the warning threshold. |
| `expiring_soon` | Present, but under `AUTOMATION_STOCKBIT_WARN_TTL_MINUTES` (default 720). |
| `expired` | Present but past its `exp`. |
| `missing` | Nothing stored. |
| `expiry_unknown` | Present, with no readable `exp` claim. Bulk jobs are allowed. |

A bulk job additionally requires at least `AUTOMATION_STOCKBIT_MIN_TTL_MINUTES` (default 90) of
remaining lifetime. A token that expires twenty minutes into an hour-long scrape is not usable,
and discovering that halfway through leaves a partial import behind. When the preflight fails,
nothing is called: the run is recorded as **`blocked_token`** with a reason naming the remedy,
and a persistent dashboard alert is raised.

Renewing:

```bash
php artisan stockbit:token:status     # source, fingerprint, expiry — never the token
php artisan stockbit:token:set        # paste, pipe, or --from-clipboard
```

…or from the token card on `/dashboard/automation`, which accepts a pasted JWT (a leading
`Bearer ` is tolerated), rejects one that is already expired, persists through the same
encrypted store, and clears the reminder.

Renewal is a person pasting a token, and the automation's job is to notice early and say so.

**Optionally**, a headless browser can do the signing in instead — see
[Headless-browser login](#headless-browser-login). It is off by default, and the default is the
recommendation: pasting a token means no password ever reaches this server.

Because this project has no mail or push transport — and one token reminder is not a good reason
to add a third-party notification dependency — the reminder is a row in `automation_alerts`,
shown in the authenticated dashboard header on every page and on the Automation page. It is
keyed on `(type, key)`, so a daily check updates one row instead of accumulating one per day, and
a healthy token clears it.

### Google Drive cold-storage synchronisation

Two collections, one authoritative mirror path each — deliberately not two layers pushing the
same files.

**OHLCV seed CSVs.** `stockbit:scrape` already hydrates before its read/merge/write loop and
flushes the CSVs it touched afterwards, via `BarCsvMirror` (`CSV_MIRROR_DISK`). The daily
automation delegates to that rather than mirroring again, and reports the result on the run row.
Only the tickers touched during the run are pushed, and a hash manifest means an unchanged file
is not re-uploaded.

**Broker-summary JSON.** New: `BrokerSummaryArchiveMirror`, plus a `broker-summary:mirror-push`
command matching `bars:mirror-push`.

```dotenv
BROKER_SUMMARY_MIRROR_DISK=gdrive   # defaults to CSV_MIRROR_DISK; empty disables mirroring
```

```bash
php artisan broker-summary:mirror-push --disk=gdrive
php artisan broker-summary:mirror-push --disk=gdrive --since=2026-08-01
```

It preserves the path under `stockbit.save_dir`, compares **content** rather than filenames,
verifies the remote copy after writing, retries throttling with backoff, and **never deletes or
modifies the local file** — a successful upload changes nothing locally, and a failed one leaves
the only good copy exactly where it was. The daily and weekly jobs mirror only the JSON they
just wrote, and only after that JSON has been imported.

A Drive failure is reported on the run (`gdrive` / `gdrive_broker_summary` in run metadata, shown
in the history) and never fails the data run. Local market data is never destroyed because cold
storage was unreachable.

### Headless-browser login

Off by default. Turning it on lets `/dashboard/scrapers` sign in to the portal through a real
Chromium and capture the bearer it issues, instead of a person copying one out of devtools.

**Understand the trade before enabling it.** Today the worst case for this server is a leaked
bearer, which expires. With this on, a portal password reaches the server on request — and a
stolen password does not expire. It is also likely to be against the portal's terms of service.
That is why it needs two environment variables set deliberately rather than a checkbox.

What the implementation does with the credentials:

- Used for **one attempt**, then discarded. Nothing stores them — no database column, no cache,
  no log, no session.
- Passed to the browser process on **stdin**, never as command-line arguments, which are
  world-readable through `ps` for the lifetime of the process.
- The child is started from an **argument array**, so there is no shell and nothing to inject
  into, consistent with the scheduler's own rule about never executing a built string.
- Only the **token** survives, through the same encrypted store a pasted token goes to. The
  response carries status, fingerprint and expiry — never the bearer.
- **No scheduled variant.** Automating renewal would mean storing the password, which is a
  materially different decision; the token reminder still exists for that reason.

The browser side lives in `apps/api/resources/browser/` and knows nothing about any particular
site — the URL, the three selectors and the token key names are all configuration:

```
resources/browser/
  token-extractor.mjs   the reusable extractBearerToken() function
  extract-token.mjs     the CLI the PHP side runs: job on stdin, JSON on stdout
  smoke-test.mjs        proves it against a fixture portal on localhost
  package.json          playwright, isolated from the API's Vite dependencies
```

It watches for the token in two places, because a portal reveals it in either or both: the body
of the login response, and the `Authorization` header of the first API call the app makes
afterwards. Watching both is what makes it reliable rather than lucky — a portal that nests or
renames the token in its body is still caught by the header, and one that redirects straight to a
static page is still caught by the body.

Install and verify, on the server:

```bash
cd apps/api/resources/browser
npm install
npm run smoke      # eight scenarios against a local fixture; no real portal, no credentials
```

> **The setup user and the web server user are not the same, and that is where
> this breaks.** `npm`, `npx` and the smoke test run as your deploy user;
> the endpoint runs as `www-data` under PHP-FPM. Two things do not survive that
> gap on their own:
>
> - **Chromium.** `npx playwright install` puts browsers under the running
>   user's home, which PHP-FPM cannot read. Install to a shared path, or point
>   at a system Chromium:
>
>   ```bash
>   sudo PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright \
>       npx playwright install --with-deps chromium
>   sudo chmod -R a+rX /opt/ms-playwright
>   # then, in .env:
>   #   BROWSER_AUTH_BROWSERS_PATH=/opt/ms-playwright
>   # or, simpler, skip Playwright's copy entirely:
>   #   BROWSER_AUTH_CHROMIUM_PATH=/usr/bin/chromium
>   ```
>
> - **node itself**, which is often on the deploy user's PATH only. Set
>   `BROWSER_AUTH_NODE_BINARY` to an absolute path (`which node`).
>
> `npm run smoke` passing proves nothing about `www-data`, because it ran as
> you. Ask the question directly instead:
>
> ```bash
> php artisan browser:check                    # as you
> sudo -u www-data php artisan browser:check   # as the web server -- the one that matters
> ```
>
> It checks configuration, the script, node, the module tree and then actually
> launches a browser, naming whichever step fails. It contacts no portal and
> takes no credentials, so it is safe to run at any time.

Then configure:

```dotenv
BROWSER_AUTH_ENABLED=true
BROWSER_AUTH_LOGIN_URL=https://portal.example.com/login
BROWSER_AUTH_USERNAME_SELECTOR=input[type="email"]
BROWSER_AUTH_PASSWORD_SELECTOR=input[type="password"]
BROWSER_AUTH_SUBMIT_SELECTOR=button[type="submit"]
BROWSER_AUTH_CHROMIUM_PATH=/usr/bin/chromium
```

Failures are reported by kind rather than as one generic error, because they need different
fixes: `INVALID_CREDENTIALS` (or a second factor this cannot answer), `TIMEOUT`,
`SELECTOR_NOT_FOUND` (the portal changed its markup), `TOKEN_NOT_FOUND` (signed in, but the token
is named something not in `BROWSER_AUTH_TOKEN_KEYS`), and `BROWSER_LAUNCH_FAILED`.

One login runs at a time — each is a Chromium process, and several at once is the quickest way to
exhaust a small VPS — and attempts are capped at five per fifteen minutes so a wrong password
cannot lock the portal account.

### Broker-summary import

The scheduled jobs import **only the files they just produced**, via
`BrokerSummaryImporter::importPaths()`. They do not run a full `broker-summary:rebuild` over the
whole archive every evening — a cost that grows with every day the system runs.

`importFromDisk()` and `broker-summary:rebuild` are unchanged and remain the full-archive
recovery path.

Import is idempotent: a window is keyed on `(asset, from_date, to_date, transaction_type)` and
its entries are replaced wholesale, so re-running a range converges rather than duplicating. It
is also why a daily window and an overlapping backfill aggregate coexist as separate rows instead
of overwriting each other.

**A multi-day Stockbit response is one aggregate over `from..to`** and is stored as exactly that
— a multi-day `BrokerSummaryWindow`. It is never written into the day-shaped legacy tables
(`broker_summary_facts`, `broksums`) stamped with Monday's or Friday's date. That rule is
unchanged and is covered by tests.

### Inspecting and diagnosing

```bash
php artisan schedule:list             # the single static entry that drives everything
php artisan scheduler:status          # every database task: schedule, next run, last outcome
php artisan scheduler:status --all    # including disabled ones
php artisan scheduler:dispatch        # run whatever is due right now
php artisan scheduler:dispatch --at="2026-08-28T09:00:00Z"   # evaluate as at a given moment
php artisan stockbit:token:status     # token source, fingerprint and expiry
php artisan automation:trading-calendar-refresh --skip-import   # rebuild the calendar in place
```

Diagnosing a missed run:

1. **A `skipped` run exists** → the scheduler fired and the market condition said no. The
   `skip_reason` says which: `not_trading_day`, `not_last_trading_day_of_week`,
   `trading_calendar_incomplete`, `week_already_summarised`, `overlapping_run`,
   `stockbit_busy`, …
   - `trading_calendar_incomplete` on *every* run means the calendar has stopped advancing.
     Check the latest `trading-calendar-refresh` run: its `days_behind` says how far it has
     fallen, and its `trading_days_import` status says whether Yahoo is reachable.
2. **A `blocked_token` run exists** → the token preflight failed. Renew it; nothing was scraped.
3. **No run at all** → the dispatcher never fired. Check that cron is invoking
   `php artisan schedule:run` every minute, and compare *Last dispatched run* on the Automation
   status header against the wall clock.
4. **Status `success` with a `Partial` badge** → the run completed but did not do everything;
   the run detail names the tickers that produced no bar, or the uploads that failed.
5. **Run history is empty and the task shows "Never run"** → confirm the task is enabled and its
   cron expression is valid (`scheduler:status` prints `invalid cron` when it is not).

Structured logs are written under `automation.task.started`, `automation.task.finished`,
`automation.dispatch.*`. Captured Artisan output is redacted of anything credential-shaped and
truncated to `AUTOMATION_MAX_OUTPUT_LENGTH` characters (default 20,000, keeping the tail) before
it is stored, so run history cannot grow without bound and a leaked bearer never reaches a
browser.

### Reliability guarantees

| Concern | How it is handled |
| --- | --- |
| Same task overlapping itself | Per-task cache lock for the whole execution. |
| Two bulk Stockbit jobs at once | Shared `automation:stockbit-bulk` lock; the second waits. |
| The same occurrence running twice | Unique index on `(scheduled_task_id, scheduled_for)`. |
| A missed minute | Catch-up window (`AUTOMATION_CATCH_UP_MINUTES`, default 5), still duplicate-safe. |
| Repeated imports | Windows keyed on the range; entries replaced, not appended. |
| Drive unreachable | Reported on the run; local files untouched; the run still succeeds. |
| Token unusable | `blocked_token` before any API call, plus a persistent alert. |
| Calendar unreadable or incomplete | Skip with a named reason. Never a guess. |
| Calendar falling behind | Refreshed daily; the lag is reported as `days_behind`, never hidden. |
| A weekday the market has not reached | Never written, so it can never be mistaken for a holiday. |
| A week that closed on an unconfirmable day | Caught up on the next week's first trading day. |
| Secrets | No bearer in the scheduler DB, in run output, in logs, or in any API response. |
| Shell injection | No shell. Allowlisted command names and validated structured parameters only. |

### Automation environment variables

```dotenv
AUTOMATION_TIMEZONE=Asia/Jakarta
AUTOMATION_CATCH_UP_MINUTES=5
AUTOMATION_DISPATCH_BUDGET_SECONDS=3300
AUTOMATION_TASK_LOCK_SECONDS=7200
AUTOMATION_STOCKBIT_LOCK_SECONDS=7200
AUTOMATION_STOCKBIT_LOCK_WAIT_SECONDS=3000
AUTOMATION_MAX_OUTPUT_LENGTH=20000
AUTOMATION_STOCKBIT_MIN_TTL_MINUTES=90
AUTOMATION_STOCKBIT_WARN_TTL_MINUTES=720
BROKER_SUMMARY_MIRROR_DISK=gdrive
```

### Directory permissions

The CLI and the web process are different users: artisan runs as the deploy user, PHP-FPM as
`www-data`. Flysystem creates a "private" directory as **0700**, so every directory the scheduler
created was one the API could not traverse — and the failure is silent, because
`Storage::exists()` simply returns `false`. A fully built reconciliation layer reported itself as
*"not built yet"* on that basis, while cold storage held a published copy of it.

`config/filesystems.php` now sets 0775/0664 on the `local` disk, matching `storage/app/private`
itself, which is already `deploy:www-data` and group-writable. That fixes directories created from
now on. **Directories created before it need a one-time fix:**

```bash
chmod -R g+rX storage/app/private
# confirm the web process can traverse it
sudo -u www-data test -r storage/app/private/reconciliation/manifest.json && echo readable
```

### Deploying this feature

```bash
php artisan migrate --force        # creates the tables and installs the four automations
php artisan config:clear

# Seed the calendar history once. Until trading_calendar covers the current
# week, every market-day condition honestly skips as trading_calendar_incomplete.
php artisan trading-days:build --from=2015-01-01 --to=$(date +%F)
php artisan trading-calendar:build --from=2015-01-01 --to=$(date +%F)

# then add the crontab entry above, and a queue worker if you want "Run now"
```

No secrets belong in any of the above. The Stockbit token is set once with
`php artisan stockbit:token:set` (or from the dashboard) and lives encrypted on the local disk.

### API

The execution workspace calls one composed endpoint:

```
GET /v1/execution/candidates
    ?date=&version=&status[]=READY&status[]=WATCH&symbol[]=BBCA
    &sector=&min_score=&min_rr=&portfolio_id=&limit=
```

It returns `signal_date`, `next_trading_date`, `version`, `thresholds`, `freshness`, `counts`,
`rows` and a `disclaimer`. Everything is assembled server-side — technicals, scores, features,
broker windows and (optionally) portfolio holdings — so the page makes one request rather than one
per row, and the ranking cannot drift from the backend's.

`portfolio_id` is authorised through `PortfolioPolicy` before any holding is attached.


All under `/api/v1`, behind the existing `auth:sanctum,jwt` middleware.

| Method | Path |
| --- | --- |
| `GET` | `/v1/scheduled-tasks` |
| `POST` | `/v1/scheduled-tasks` |
| `GET` | `/v1/scheduled-tasks/{id}` |
| `PUT` / `PATCH` | `/v1/scheduled-tasks/{id}` |
| `DELETE` | `/v1/scheduled-tasks/{id}` |
| `POST` | `/v1/scheduled-tasks/{id}/toggle` |
| `POST` | `/v1/scheduled-tasks/{id}/run` |
| `GET` | `/v1/scheduled-tasks/{id}/runs` |
| `GET` | `/v1/automation/status` |
| `GET` | `/v1/automation/runs` |
| `GET` | `/v1/automation/alerts` |
| `DELETE` | `/v1/automation/alerts/{id}` |
| `GET` | `/v1/automation/stockbit-token` |
| `PUT` | `/v1/automation/stockbit-token` |
| `DELETE` | `/v1/automation/stockbit-token` |
| `GET` | `/v1/backup-status` |
| `GET` | `/v1/backup-status/audit` |
| `POST` | `/v1/backup-status/mirror-push` |
| `GET` | `/v1/reconciliation` |
| `GET` | `/v1/reconciliation/{symbol}` |

The backup and reconciliation endpoints are described under
[Data resilience](#data-resilience-the-three-layers); the short version is that
`/v1/backup-status` is the cheap readiness answer and `/v1/backup-status/audit` is the expensive
file-by-file one.

`GET /v1/automation/stockbit-token` returns status only — configured, source, fingerprint
(`****abcd`), expiry, remaining duration. There is no endpoint that returns the bearer.

## CI / CD

Two workflows in `.github/workflows/`.

### `ci.yml` — runs on every pull request and push to `main`

| Job | Checks |
| --- | --- |
| **API — Laravel tests** | `php artisan test` on PHP 8.2, `composer check-platform-reqs`, `composer audit` |
| **Web — types, build, lint** | `tsc --noEmit`, `eslint`, `next build`, `npm audit --audit-level=critical` |
| **API — Pint** | `pint --test` |

All three block merges. The whole suite finishes in under a minute.

Two details worth knowing if you edit it:

- **PHP 8.2 is deliberate** — it is the floor `apps/api/composer.json` declares, and `config.platform.php` pins the resolver to it. Without that pin, running `composer update` on a newer machine silently produces a lockfile that cannot install on 8.2. `composer check-platform-reqs` in CI catches that drift.
- **No `.env` is written.** `phpunit.xml` already pins the test environment; only `APP_KEY` is missing and it is exported as a throwaway. `php artisan key:generate` cannot be used here — it boots `AppServiceProvider`, which resolves `JwtService`, which throws while `APP_KEY` and `JWT_SECRET` are both empty.

### `backend-deploy.yml` — deploys `apps/api` to the VPS

Triggered by `ci.yml` completing successfully on `main`, so a deploy can never ship a commit the tests rejected. It deploys the exact commit CI validated, not whatever `main` has become since. `workflow_dispatch` allows a manual re-deploy.

The web app is **not** covered here: Vercel builds and deploys `apps/web` from `main` on its own.

**The workflow is inert until you switch it on.** It is gated on a repository variable, so merging it changes nothing:

```
Settings → Secrets and variables → Actions → Variables
  DEPLOY_ENABLED = true
```

Required secrets, on a `production` GitHub Environment (`Settings → Environments → production`), which also lets you add required reviewers or a wait timer:

| Secret | Purpose |
| --- | --- |
| `DEPLOY_HOST` | VPS hostname or IP |
| `DEPLOY_USER` | SSH user owning the deploy directory |
| `DEPLOY_SSH_KEY` | Private key, full PEM contents. Use a dedicated deploy key, not a personal one |
| `DEPLOY_PATH` | Absolute path to the **repository root** on the server (the workflow `cd`s into `apps/api` itself) |
| `DEPLOY_PORT` | Optional, defaults to `22` |
| `DEPLOY_KNOWN_HOSTS` | Strongly recommended. Output of `ssh-keyscan -H <host>`. Without it the workflow falls back to trusting the host on first contact, which leaves the deploy key exposed to a man-in-the-middle |

What it runs on the server:

```bash
git fetch --prune origin && git reset --hard <the SHA CI validated>
php artisan down --retry=15
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up          # via a trap, so it runs even if a step above fails
```

Server-side prerequisites, one time: the repo cloned at `DEPLOY_PATH`, a complete `.env` in `apps/api` (`config:cache` bakes it in, so it must be correct before the first deploy), PHP and Composer on `PATH` for the deploy user, write access to `storage/` and `bootstrap/cache/`, and an nginx vhost pointing at `apps/api/public`.

The vhost is **not** deployed by the workflow — it is installed once by hand — but a known-good one is committed at [`deploy/nginx/breakout-api.conf`](deploy/nginx/breakout-api.conf). Two of its settings are load-bearing and worth not rediscovering:

- The `:80` block returns **308**, not 301. A 301 lets the client re-issue the request as GET without its body, so every POST to the API arrives at Laravel as a GET and fails with `MethodNotAllowedHttpException`.
- The PHP location does `include fastcgi_params;`. Without it `REQUEST_URI` never reaches PHP, Laravel routes everything to `/`, and the whole API silently answers 200 with the welcome page.

It is also IPv4-only as committed — uncomment the `listen [::]` lines only if the host has IPv6, since they fail `nginx -t` outright otherwise. See [Troubleshooting](apps/api/README.md#troubleshooting) in the API README for the diagnostic.

Note `git reset --hard` discards anything uncommitted on the server — deliberate, so the deployed tree always matches the commit, but do not hand-edit files there.

**Do the first run manually** via *Actions → Deploy API → Run workflow*, and watch it. Deployment is the one part of this that cannot be rehearsed in CI.

A queue worker is a separate, ongoing concern: `queue:restart` above only signals a running worker to pick up new code, it does not start one. Rule-builder strategy runs are queued (`QUEUE_CONNECTION=database`), so without a supervised `php artisan queue:work` in `apps/api` they stay in `queued` forever.

#### The deploy fails in about five seconds

That is the `Configure SSH` step, before anything reaches the server. Open the failed run and expand it — the step prints its resolved environment:

```
env:
  DEPLOY_SSH_KEY:
  DEPLOY_KNOWN_HOSTS:
  DEPLOY_HOST:
  DEPLOY_PORT: 22
```

Blank values mean the secrets are not readable by the job. GitHub renders a set secret as `***`, never as an empty string, so empty is proof they are missing rather than wrong. `DEPLOY_PORT: 22` is the workflow's own `|| '22'` default and says nothing either way.

The job runs at all only when `DEPLOY_ENABLED` is `true`, so reaching this step means the variable is set and the secrets are not. They must live either on the **`production` environment** (`Settings → Environments → production → Environment secrets`) or on the repository (`Settings → Secrets and variables → Actions`) — an environment-scoped job reads both, but a secret added to a *different* environment is invisible to it.

With an empty `DEPLOY_HOST` the fallback `ssh-keyscan` exits non-zero, and the step runs under `bash -e`, which is why the whole job stops there with exit code 1 rather than continuing to a clearer error.

## Repository Layout
```
.
├── README.md
├── deploy/
│   └── nginx/      Reference vhost for the API (installed by hand, not by CI)
└── apps/
    ├── api/        Laravel API
    │   ├── app/            Domain code (Models, Services, Console, etc.)
    │   ├── routes/         API routes (api.php), console commands
    │   ├── database/       Migrations, factories, seeders, seed data
    │   ├── resources/      Blade views, language files
    │   └── tests/          Feature and unit tests
    └── web/        Next.js app
        ├── app/            App Router routes and pages
        ├── components/     Shared React components
        ├── lib/            Utilities and helpers
        └── public/         Static assets
```

## Contributing
Pull requests are welcome. Please ensure tests pass before submitting your contribution.
