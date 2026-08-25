<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyRunMatch extends Model
{
    protected $table = 'strategy_run_matches';

    protected $guarded = ['id'];

    protected $casts = [
        'strategy_run_id' => 'integer',
        'asset_id' => 'integer',
        'facts' => 'array',
        'explanation' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(StrategyRun::class, 'strategy_run_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
