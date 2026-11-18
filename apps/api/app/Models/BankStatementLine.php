<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankStatementLine extends Model
{
    protected $fillable = ['bank_statement_id', 'transacted_on', 'description', 'direction', 'amount', 'reference', 'fee_payment_id', 'finance_expense_id'];

    protected function casts(): array
    {
        return ['transacted_on' => 'immutable_date', 'amount' => 'decimal:2'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    public function feePayment(): BelongsTo
    {
        return $this->belongsTo(FeePayment::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(FinanceExpense::class, 'finance_expense_id');
    }
}
