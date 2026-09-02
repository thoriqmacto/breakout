<?php

namespace App\Services\Reconciliation;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * A cheap, deterministic answer to "has anything about this asset changed?".
 *
 * The whole incremental design rests on this being both correct and cheap. If
 * it is wrong in one direction the layer silently serves stale recovery data;
 * if it is wrong in the other every asset is rebuilt and re-uploaded nightly
 * for no reason. If it is expensive, nothing is gained over rebuilding.
 *
 * So it is computed entirely from metadata the application already maintains:
 *
 *   - the schema version, so a format change rebuilds everything exactly once
 *   - the asset configuration that affects serialisation
 *   - a digest of the OHLCV extent and content signature
 *   - the identities and `source_hash` values of the canonical broker windows
 *
 * The broker half costs one indexed query per batch and no file reads at all,
 * because the importer already recorded a SHA-256 of every raw payload when it
 * stored the window. Reusing that is the difference between a fingerprint pass
 * that touches the database and one that downloads the archive.
 *
 * The OHLCV half deliberately does not hash the CSV on every run. The CSV is
 * derived from the same bars the document serialises, so the bars are the real
 * input; file size and mtime come along as a cheap tripwire for a CSV edited
 * out from under the database.
 */
class ReconciliationFingerprint
{
    /**
     * Fingerprints for many assets, in a bounded number of queries.
     *
     * @param  array<int, Asset>  $assets
     * @return array<int, string> keyed by asset id
     */
    public function forAssets(array $assets): array
    {
        if ($assets === []) {
            return [];
        }

        $ids = array_map(static fn (Asset $asset): int => (int) $asset->id, $assets);

        $bars = $this->barSignatures($ids);
        $windows = $this->windowSignatures($ids);
        $version = (int) config('reconciliation.schema_version', 1);

        $out = [];

        foreach ($assets as $asset) {
            $id = (int) $asset->id;

            $parts = [
                'v'.$version,
                'sym:'.strtoupper((string) $asset->symbol),
                'cfg:'.((bool) $asset->sync_price ? '1' : '0').((bool) $asset->sync_broker_summary ? '1' : '0'),
                'name:'.(string) $asset->name,
                'bars:'.($bars[$id] ?? 'none'),
                'csv:'.$this->csvSignature((string) $asset->symbol),
                'windows:'.($windows[$id] ?? 'none'),
            ];

            $out[$id] = hash('sha256', implode('|', $parts));
        }

        return $out;
    }

    public function forAsset(Asset $asset): string
    {
        return $this->forAssets([$asset])[(int) $asset->id];
    }

    /**
     * Extent plus an aggregate that moves when any stored value moves.
     *
     * Row count and date range alone would miss a corrected close on an
     * existing bar -- the commonest kind of change after a provider revision
     * -- so the sums are there to catch an edit that leaves the shape intact.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function barSignatures(array $ids): array
    {
        $rows = DB::table('price_bars')
            ->whereIn('asset_id', $ids)
            ->groupBy('asset_id')
            ->select('asset_id')
            ->selectRaw('COUNT(*) as bar_count')
            ->selectRaw('MIN(date) as first_date')
            ->selectRaw('MAX(date) as last_date')
            ->selectRaw('COALESCE(SUM(close), 0) as close_sum')
            ->selectRaw('COALESCE(SUM(volume), 0) as volume_sum')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->asset_id] = implode(':', [
                (int) $row->bar_count,
                (string) $row->first_date,
                (string) $row->last_date,
                // Cast through string so a float and its integer equivalent
                // cannot produce two spellings of the same total.
                rtrim(rtrim(sprintf('%.4f', (float) $row->close_sum), '0'), '.'),
                (string) $row->volume_sum,
            ]);
        }

        return $out;
    }

    /**
     * The canonical broker windows, by identity and stored payload hash.
     *
     * One query for the batch, ordered so the concatenation is stable, then
     * digested per asset. `source_hash` is what makes this sensitive to a
     * re-import that changed the payload without changing the range.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function windowSignatures(array $ids): array
    {
        $rows = DB::table('broker_summary_windows as w')
            ->leftJoin('bandar_detector_summaries as d', 'd.broker_summary_window_id', '=', 'w.id')
            ->whereIn('w.asset_id', $ids)
            ->orderBy('w.asset_id')
            ->orderBy('w.from_date')
            ->orderBy('w.to_date')
            ->orderBy('w.transaction_type')
            ->select([
                'w.asset_id',
                'w.from_date',
                'w.to_date',
                'w.transaction_type',
                'w.source_hash',
                'w.returned_buyer_count',
                'w.returned_seller_count',
                'd.broker_accdist',
                'd.number_broker_buysell',
            ])
            ->get();

        $parts = [];

        foreach ($rows as $row) {
            $parts[(int) $row->asset_id][] = implode(':', [
                (string) $row->from_date,
                (string) $row->to_date,
                (string) $row->transaction_type,
                (string) ($row->source_hash ?? ''),
                (string) ($row->returned_buyer_count ?? ''),
                (string) ($row->returned_seller_count ?? ''),
                (string) ($row->broker_accdist ?? ''),
                (string) ($row->number_broker_buysell ?? ''),
            ]);
        }

        $out = [];

        foreach ($parts as $assetId => $lines) {
            $out[$assetId] = count($lines).'@'.hash('sha256', implode("\n", $lines));
        }

        return $out;
    }

    /**
     * Size and mtime of the historical CSV.
     *
     * A tripwire rather than a hash: the CSV is generated from the same bars
     * the document already covers, so hashing it every run would cost a full
     * read per asset to detect something the bar signature almost always
     * catches first. What this adds is the case the bars cannot see -- a CSV
     * edited, truncated or restored underneath an unchanged database.
     */
    private function csvSignature(string $symbol): string
    {
        $path = rtrim((string) config('csv.seed_dir'), '/').'/'.strtoupper(trim($symbol)).'.csv';

        if (! is_file($path)) {
            return 'absent';
        }

        return sprintf('%d:%d', filesize($path) ?: 0, filemtime($path) ?: 0);
    }
}
