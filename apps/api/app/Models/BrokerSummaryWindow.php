<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One Stockbit broker-summary retrieval: an aggregate over from_date..to_date.
 *
 * A single-day summary is the special case from_date === to_date, so there is
 * no separate daily model.
 */
class BrokerSummaryWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id',
        'from_date',
        'to_date',
        'transaction_type',
        'market_board',
        'investor_type',
        'requested_limit',
        'returned_buyer_count',
        'returned_seller_count',
        'total_buyer',
        'total_seller',
        'source_filename',
        'source_hash',
        'imported_at',
    ];

    protected $casts = [
        'from_date' => 'date:Y-m-d',
        'to_date' => 'date:Y-m-d',
        'requested_limit' => 'int',
        'returned_buyer_count' => 'int',
        'returned_seller_count' => 'int',
        'total_buyer' => 'int',
        'total_seller' => 'int',
        'imported_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(BrokerSummaryEntry::class);
    }

    public function buyers(): HasMany
    {
        return $this->entries()->where('side', BrokerSummaryEntry::SIDE_BUY);
    }

    public function sellers(): HasMany
    {
        return $this->entries()->where('side', BrokerSummaryEntry::SIDE_SELL);
    }

    public function bandarDetectorSummary(): HasOne
    {
        return $this->hasOne(BandarDetectorSummary::class);
    }

    public function isSingleDay(): bool
    {
        return $this->from_date?->toDateString() === $this->to_date?->toDateString();
    }

    /**
     * Whether Stockbit returned fewer brokers than it says exist.
     *
     * The request limit caps each list, so a window can hold 25 of 42 buyers.
     * A null total means Stockbit did not say, which is not the same as
     * "complete" -- it is reported as unknown rather than assumed.
     */
    public function buyersTruncated(): bool
    {
        return $this->total_buyer !== null && $this->returned_buyer_count < $this->total_buyer;
    }

    public function sellersTruncated(): bool
    {
        return $this->total_seller !== null && $this->returned_seller_count < $this->total_seller;
    }
}
