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
            'breakout20' => $this->isBreakout20(),
            'warnings' => $this->warnings,
        ];
    }
}
