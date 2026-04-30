<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Models\WatchlistScore;
use Illuminate\Http\Request;

class StrategyWatchlistController extends ApiController
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'date' => 'sometimes|date',
            'version' => 'sometimes|string|max:32',
            'symbol' => 'sometimes|string|max:20',
            'min_score' => 'sometimes|numeric|min:0|max:100',
            'limit' => 'sometimes|integer|min:1|max:200',
        ]);

        $version = $data['version'] ?? 'v1';
        $limit = (int) ($data['limit'] ?? 50);

        $resolvedDate = isset($data['date'])
            ? $data['date']
            : (string) WatchlistScore::query()->where('version', $version)->max('scan_date');

        if ($resolvedDate === '' || $resolvedDate === null) {
            return ApiResponse::success([
                'scan_date' => null,
                'version' => $version,
                'rows' => [],
            ]);
        }

        $query = WatchlistScore::query()
            ->where('scan_date', $resolvedDate)
            ->where('version', $version)
            ->with('asset:id,symbol,name,sector')
            ->orderByDesc('score_total');

        if (isset($data['symbol'])) {
            $query->where('symbol', strtoupper($data['symbol']));
        }
        if (isset($data['min_score'])) {
            $query->where('score_total', '>=', (float) $data['min_score']);
        }

        $rows = $query->limit($limit)->get();

        return ApiResponse::success([
            'scan_date' => $resolvedDate,
            'version' => $version,
            'rows' => $rows->map(static function (WatchlistScore $row): array {
                return [
                    'id' => (int) $row->id,
                    'scan_date' => $row->scan_date?->toDateString(),
                    'symbol' => $row->symbol,
                    'asset' => $row->asset ? [
                        'id' => (int) $row->asset->id,
                        'symbol' => $row->asset->symbol,
                        'name' => $row->asset->name,
                        'sector' => $row->asset->sector,
                    ] : null,
                    'version' => $row->version,
                    'close' => $row->close === null ? null : (float) $row->close,
                    'net_value' => $row->net_value === null ? null : (float) $row->net_value,
                    'vol_ratio_20' => $row->vol_ratio_20 === null ? null : (float) $row->vol_ratio_20,
                    'breakout20' => (bool) $row->breakout20,
                    'score_total' => (float) $row->score_total,
                    'score_bas' => (float) $row->score_bas,
                    'score_bcs' => (float) $row->score_bcs,
                    'lf_pass' => (bool) $row->lf_pass,
                    'rrf_pass' => (bool) $row->rrf_pass,
                    'invalidation_level' => $row->invalidation_level === null ? null : (float) $row->invalidation_level,
                    'take_profit' => $row->take_profit === null ? null : (float) $row->take_profit,
                    'risk_reward' => $row->risk_reward === null ? null : (float) $row->risk_reward,
                    'top_brokers' => $row->top_brokers ?? [],
                    'reasons' => $row->reasons ?? [],
                    'risk_notes' => $row->risk_notes,
                ];
            })->all(),
            'disclaimer' => 'Research watchlist support only. No profit guarantee, no trading execution.',
        ]);
    }
}
