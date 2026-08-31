<?php

namespace App\Services;

/**
 * What one Yahoo import asked for, what the provider supplied, and what the
 * database ended up holding.
 *
 * The three used to be conflated. `trading-days:build` printed "Upserted N
 * trading day records (N with a close value)" where the second number counted
 * rows already in the table for the range -- so a run that fetched nothing
 * useful still reported a count, and a session the provider had supplied but
 * the database had not stored looked identical to a clean success. Keeping the
 * provider's answer and the database's answer apart is what lets a caller say
 * "Yahoo gave us this and we failed to persist it".
 */
final class TradingDayImportReport
{
    /**
     * @param  array<string, float|null>  $providerCloses  Date => close, as supplied, inside the requested range.
     * @param  array<int, string>  $repaired  Dates whose stored close went from NULL to a number.
     * @param  array<int, string>  $preserved  Dates where the provider had no close and a stored one was kept.
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $fetchedFrom,
        public readonly array $providerCloses = [],
        public readonly array $repaired = [],
        public readonly array $preserved = [],
    ) {}

    /**
     * Sessions the provider returned inside the requested range.
     */
    public function providerSessions(): int
    {
        return count($this->providerCloses);
    }

    /**
     * Of those, the ones that carried an actual number.
     */
    public function providerClosesCount(): int
    {
        return count(array_filter($this->providerCloses, static fn (?float $close): bool => $close !== null));
    }

    /**
     * Dates the provider gave a number for.
     *
     * @return array<int, string>
     */
    public function datesWithProviderClose(): array
    {
        return array_keys(array_filter($this->providerCloses, static fn (?float $close): bool => $close !== null));
    }
}
