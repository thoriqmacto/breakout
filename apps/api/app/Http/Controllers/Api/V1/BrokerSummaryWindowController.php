<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ApiResponse;
use App\Http\Resources\BrokerSummaryEntryResource;
use App\Http\Resources\BrokerSummaryWindowResource;
use App\Models\BrokerSummaryEntry;
use App\Models\BrokerSummaryWindow;
use Illuminate\Http\Request;

/**
 * Range-aware broker summaries.
 *
 * The older /broker-summary endpoint reads the legacy broksums table, whose
 * single `date` column cannot express a range. These endpoints serve the
 * canonical window model instead.
 *
 * Date filters are deliberately named window_from / window_to rather than
 * from / to, because they no longer mean "rows between these days" and reusing
 * the old names would make the change invisible at the call site.
 */
class BrokerSummaryWindowController extends ApiController
{
    /**
     * Windows for a symbol, newest range first.
     *
     * Matching is exact by default: window_from and window_to select the
     * window with precisely those endpoints, which is what you want when
     * picking out one imported aggregate. `match=overlap` instead returns
     * every window intersecting the requested range
     * (stored.from <= requested_to AND stored.to >= requested_from), which is
     * a different question and so has to be asked for.
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'symbol' => ['sometimes', 'string', 'max:20'],
            'window_from' => ['sometimes', 'date'],
            'window_to' => ['sometimes', 'date'],
            'match' => ['sometimes', 'in:exact,overlap'],
            'transaction_type' => ['sometimes', 'string', 'max:50'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = BrokerSummaryWindow::query()
            ->with(['asset:id,symbol,name', 'bandarDetectorSummary'])
            ->orderByDesc('to_date')
            ->orderByDesc('from_date');

        if (isset($data['symbol'])) {
            $symbol = strtoupper($data['symbol']);
            $query->whereHas('asset', fn ($q) => $q->where('symbol', $symbol));
        }

        if (isset($data['transaction_type'])) {
            $query->where('transaction_type', $data['transaction_type']);
        }

        $this->applyRange($query, $data);

        $windows = $query->paginate($data['per_page'] ?? 25)->withQueryString();

        return ApiResponse::success([
            'windows' => BrokerSummaryWindowResource::collection($windows->items()),
            'meta' => $this->pagination($windows),
        ]);
    }

    /**
     * One window with its full broker lists and detector.
     */
    public function show(BrokerSummaryWindow $window)
    {
        $window->load([
            'asset:id,symbol,name',
            'bandarDetectorSummary',
            'buyers' => fn ($q) => $q->orderByDesc('net_value'),
            'sellers' => fn ($q) => $q->orderBy('net_value'),
        ]);

        return ApiResponse::success([
            'window' => new BrokerSummaryWindowResource($window),
        ]);
    }

    /**
     * Broker entries across windows, paginated and sorted server-side.
     *
     * The whole dataset is never shipped to the browser, so no arbitrary row
     * cap is needed to keep the page responsive.
     */
    public function entries(Request $request)
    {
        $data = $request->validate([
            'symbol' => ['sometimes', 'string', 'max:20'],
            'window_from' => ['sometimes', 'date'],
            'window_to' => ['sometimes', 'date'],
            'match' => ['sometimes', 'in:exact,overlap'],
            'transaction_type' => ['sometimes', 'string', 'max:50'],
            'broker' => ['sometimes', 'string', 'max:20'],
            'broker_type' => ['sometimes', 'string', 'max:20'],
            'side' => ['sometimes', 'in:buy,sell'],
            'sort' => ['sometimes', 'in:net_value,net_lot,gross_value,gross_volume,frequency,broker_code,average_price'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = BrokerSummaryEntry::query()
            ->with(['window.asset:id,symbol,name']);

        $query->whereHas('window', function ($windowQuery) use ($data) {
            if (isset($data['symbol'])) {
                $symbol = strtoupper($data['symbol']);
                $windowQuery->whereHas('asset', fn ($q) => $q->where('symbol', $symbol));
            }

            if (isset($data['transaction_type'])) {
                $windowQuery->where('transaction_type', $data['transaction_type']);
            }

            $this->applyRange($windowQuery, $data);
        });

        if (isset($data['broker'])) {
            $query->where('broker_code', strtoupper($data['broker']));
        }

        if (isset($data['broker_type'])) {
            $query->where('broker_type', $data['broker_type']);
        }

        if (isset($data['side'])) {
            $query->where('side', $data['side']);
        }

        $query->orderBy($data['sort'] ?? 'net_value', $data['direction'] ?? 'desc');

        $entries = $query->paginate($data['per_page'] ?? 50)->withQueryString();

        return ApiResponse::success([
            'entries' => BrokerSummaryEntryResource::collection($entries->items()),
            'meta' => $this->pagination($entries),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyRange(mixed $query, array $data): void
    {
        $from = $data['window_from'] ?? null;
        $to = $data['window_to'] ?? null;

        if ($from === null && $to === null) {
            return;
        }

        if (($data['match'] ?? 'exact') === 'overlap') {
            if ($to !== null) {
                $query->whereDate('from_date', '<=', $to);
            }

            if ($from !== null) {
                $query->whereDate('to_date', '>=', $from);
            }

            return;
        }

        if ($from !== null) {
            $query->whereDate('from_date', '=', $from);
        }

        if ($to !== null) {
            $query->whereDate('to_date', '=', $to);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pagination(mixed $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
