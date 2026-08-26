<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BandarDetectorSummary extends Model
{
    protected $table = 'bandar_detector_summaries';

    protected $fillable = [
        'asset_id',
        'broker_summary_window_id',
        'from_date',
        'to_date',
        'transaction_type',
        'broker_accdist',
        'number_broker_buysell',
        'total_buyer',
        'total_seller',
        'value',
        'volume',
        'average_price',
        'metrics_json',
    ];

    protected $casts = [
        // Date-only, and formatted as such. A bare 'date' cast round-trips as
        // "2026-05-26 00:00:00", which does not match the stored DATE value,
        // so updateOrCreate looked the row up and missed every time.
        'from_date' => 'date:Y-m-d',
        'to_date' => 'date:Y-m-d',
        'number_broker_buysell' => 'int',
        'total_buyer' => 'int',
        'total_seller' => 'int',
        'value' => 'float',
        'volume' => 'int',
        'average_price' => 'float',
        'metrics_json' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * The retrieval window this detector describes.
     *
     * Nullable for rows imported before windows existed; those are relinked
     * by broker-summary:rebuild.
     */
    public function window(): BelongsTo
    {
        return $this->belongsTo(BrokerSummaryWindow::class, 'broker_summary_window_id');
    }
}
