<?php

namespace App\Services\Analysis;

/**
 * One asset's technical picture as it stood at the close of one session.
 *
 * Immutable and self-describing on purpose. Every consumer -- the CLI table,
 * the metrics API, the watchlist ranker, the execution candidate builder --
 * reads these fields rather than recomputing a moving average of its own, so
 * "close vs the 55-week high" cannot mean two different numbers depending on
 * which surface asked.
 *
 * `as_of_date` is the date of the bar the snapshot was actually built from,
 * which is the last session at or before the date requested. It is not the
 * requested date, and the difference is the point: asking for a Sunday, or for
 * a day the exchange was shut, answers with the session that really closed.
 */
final class AssetTechnicalSnapshot
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public readonly int $assetId,
        public readonly string $symbol,
        public readonly ?string $name,
        public readonly ?string $sector,
        public readonly string $requestedAsOf,
        public readonly string $asOfDate,
        public readonly int $bars,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly float $volume,
        public readonly ?float $ma50,
        public readonly ?float $ma100,
        public readonly ?float $ma150,
        public readonly bool $uptrend,
        public readonly ?float $high20w,
        public readonly ?float $high55w,
        public readonly ?float $atr14,
        public readonly ?float $roc13,
        public readonly ?float $avgVol20,
        public readonly ?float $volRatio20,
        public readonly ?float $closeVsHigh20,
        public readonly ?float $closeVsHigh55,
        public readonly ?float $priorHigh20,
        public readonly ?float $swingLow20,
        public readonly ?float $closePos,
        public readonly ?float $ema20 = null,
        public readonly ?float $ema50 = null,
        public readonly ?float $priorHigh55 = null,
        public readonly ?float $prevClose = null,
        public readonly ?float $gapPct = null,
        public readonly ?bool $compression = null,
        public readonly array $warnings = [],
    ) {}

    /**
     * Whether the session's close cleared the highest high of the twenty
     * sessions before it.
     *
     * Deliberately the same comparison FeatureExtractionService makes for
     * `breakout20`, against the same reference, so the execution plan and the
     * stored feature can never disagree about whether a breakout happened.
     */
    public function isBreakout20(): bool
    {
        return $this->priorHigh20 !== null && $this->close > $this->priorHigh20;
    }

    /**
     * The same comparison against the fifty-five sessions before this one.
     *
     * Not to be confused with `high55w`, which is the fifty-five *week* high
     * and a different level entirely. A close through the 55-session high is
     * a stronger structural statement than one through the 20-session high,
     * and reporting both lets a breakout be graded rather than merely
     * detected.
     */
    public function isBreakout55(): bool
    {
        return $this->priorHigh55 !== null && $this->close > $this->priorHigh55;
    }

    /**
     * How far the close sits below its breakout level, measured in ATR.
     *
     * Zero once the level has been cleared. This is what separates a setup
     * worth watching from one worth arming: "3% below the level" means
     * nothing without knowing what a normal session is worth, and on a stock
     * whose ATR is 4% it means the next session could do it.
     */
    public function distanceToBreakoutAtr(): ?float
    {
        if ($this->priorHigh20 === null || $this->atr14 === null || $this->atr14 <= 0) {
            return null;
        }

        if ($this->close >= $this->priorHigh20) {
            return 0.0;
        }

        return round(($this->priorHigh20 - $this->close) / $this->atr14, 4);
    }

    /**
     * Distance to the breakout level as a percentage of the close.
     */
    public function distanceToBreakoutPct(): ?float
    {
        if ($this->priorHigh20 === null || $this->close <= 0) {
            return null;
        }

        if ($this->close >= $this->priorHigh20) {
            return 0.0;
        }

        return round((($this->priorHigh20 - $this->close) / $this->close) * 100.0, 4);
    }

    /**
     * Whether the short exponential average sits above the medium one --
     * the trend-quality condition, kept separate from the 150-session
     * `uptrend` flag because they disagree usefully during a turn.
     */
    public function emaAligned(): ?bool
    {
        if ($this->ema20 === null || $this->ema50 === null) {
            return null;
        }

        return $this->ema20 >= $this->ema50;
    }

    public function aboveEma20(): ?bool
    {
        return $this->ema20 === null ? null : $this->close > $this->ema20;
    }

    /**
     * The comparison key behind structural rank, strongest first.
     *
     * This is the single definition of "structurally strong", and the only
     * thing permitted to order a structural list. It answers how a stock sits
     * relative to its own trend and its own highs -- nothing about brokers,
     * accumulation or execution timing belongs in it, because those answer a
     * different question and are scored separately.
     *
     * @return array<int, float|int>
     */
    public function structuralSortKey(): array
    {
        return [
            $this->uptrend ? 1 : 0,
            (float) ($this->roc13 ?? 0.0),
            (float) ($this->closeVsHigh55 ?? 0.0),
            (float) ($this->closeVsHigh20 ?? 0.0),
            (float) ($this->volRatio20 ?? 0.0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'asset_id' => $this->assetId,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'sector' => $this->sector,
            'requested_as_of' => $this->requestedAsOf,
            'as_of_date' => $this->asOfDate,
            'bars' => $this->bars,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
            'ma50' => $this->ma50,
            'ma100' => $this->ma100,
            'ma150' => $this->ma150,
            'uptrend' => $this->uptrend,
            'high20w' => $this->high20w,
            'high55w' => $this->high55w,
            'atr14' => $this->atr14,
            'roc13' => $this->roc13,
            'avg_vol20' => $this->avgVol20,
            'vol_ratio_20' => $this->volRatio20,
            'close_vs_high20' => $this->closeVsHigh20,
            'close_vs_high55' => $this->closeVsHigh55,
            'prior_high20' => $this->priorHigh20,
            'swing_low20' => $this->swingLow20,
            'close_pos' => $this->closePos,
            'ema20' => $this->ema20,
            'ema50' => $this->ema50,
            'prior_high55' => $this->priorHigh55,
            'prev_close' => $this->prevClose,
            'gap_pct' => $this->gapPct,
            'compression' => $this->compression,
            'breakout20' => $this->isBreakout20(),
            'breakout55' => $this->isBreakout55(),
            'distance_to_breakout_atr' => $this->distanceToBreakoutAtr(),
            'distance_to_breakout_pct' => $this->distanceToBreakoutPct(),
            'ema_aligned' => $this->emaAligned(),
            'above_ema20' => $this->aboveEma20(),
            'warnings' => $this->warnings,
        ];
    }
}
