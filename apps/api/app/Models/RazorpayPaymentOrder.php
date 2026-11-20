<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RazorpayPaymentOrder extends Model
{
    protected $fillable = [
        'organization_id', 'fee_record_id', 'payer_user_id', 'fee_payment_id', 'receipt',
        'gateway_order_id', 'gateway_payment_id', 'idempotency_key', 'amount_paise', 'currency', 'status', 'captured_at',
    ];

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'captured_at' => 'immutable_datetime'];
    }

    public function feeRecord(): BelongsTo
    {
        return $this->belongsTo(FeeRecord::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function feePayment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class);
    }
}
