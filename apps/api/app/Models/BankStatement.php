<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatement extends Model
{
    protected $fillable = ['organization_id', 'created_by', 'account_label', 'fingerprint', 'period_from', 'period_to', 'opening_balance', 'closing_balance', 'line_count'];

    protected function casts(): array
    {
        return ['period_from' => 'immutable_date', 'period_to' => 'immutable_date', 'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }
}
