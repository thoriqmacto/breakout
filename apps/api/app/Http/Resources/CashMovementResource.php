<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'portfolio_id' => (int) $this->portfolio_id,
            'kind' => $this->kind,
            'amount' => (float) $this->amount,
            'signed_amount' => $this->signedAmount(),
            'executed_at' => $this->executed_at?->toDateString(),
            'note' => $this->note,
        ];
    }
}
