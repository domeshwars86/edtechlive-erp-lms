<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeePayment extends Model
{
    protected $fillable = ['organization_id', 'fee_record_id', 'amount', 'method', 'reference', 'received_by', 'received_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'received_at' => 'immutable_datetime'];
    }

    public function feeRecord(): BelongsTo
    {
        return $this->belongsTo(FeeRecord::class);
    }
}
