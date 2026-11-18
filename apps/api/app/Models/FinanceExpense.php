<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceExpense extends Model
{
    protected $fillable = ['organization_id', 'recorded_by', 'title', 'category', 'amount', 'incurred_on', 'payment_method', 'reference', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'incurred_on' => 'immutable_date'];
    }
}
