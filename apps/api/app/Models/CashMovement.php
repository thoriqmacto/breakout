<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    public const KIND_DEPOSIT = 'deposit';

    public const KIND_WITHDRAW = 'withdraw';

    public const KIND_FEE = 'fee';

    public const KIND_DIVIDEND = 'dividend';

    public const KIND_ADJUSTMENT = 'adjustment';

    public const KINDS = [
        self::KIND_DEPOSIT,
        self::KIND_WITHDRAW,
        self::KIND_FEE,
        self::KIND_DIVIDEND,
        self::KIND_ADJUSTMENT,
    ];

    /**
     * Sign convention applied when summing movements into the running cash
     * balance. Deposits and dividends add cash; withdrawals and fees
     * subtract cash; adjustments take their value as-is so a manual
     * correction can move the balance either direction.
     */
    public const SIGNED_KINDS = [
        self::KIND_DEPOSIT => 1,
        self::KIND_WITHDRAW => -1,
        self::KIND_FEE => -1,
        self::KIND_DIVIDEND => 1,
        self::KIND_ADJUSTMENT => 1,
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'portfolio_id' => 'integer',
        'amount' => 'float',
        // Matches positions: an imported dividend carries a broker timestamp.
        'executed_at' => 'datetime',
    ];

    /**
     * Movements the Stockbit history importer created, e.g. cash dividends.
     */
    public const SOURCE_STOCKBIT = 'stockbit';

    /**
     * A base-cash correction written when the user reconciles against the
     * broker's reported cash balance.
     */
    public const SOURCE_STOCKBIT_SNAPSHOT = 'stockbit_snapshot';

    public function portfolio(): BelongsTo
    {
        return $this->belongsTo(Portfolio::class);
    }

    /**
     * Signed amount of this movement, ready to be summed into a running
     * cash balance. Adjustments may carry a negative amount on input.
     */
    public function signedAmount(): float
    {
        $sign = self::SIGNED_KINDS[$this->kind] ?? 1;

        return $sign * (float) $this->amount;
    }
}
