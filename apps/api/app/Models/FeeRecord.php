<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeRecord extends Model
{
    protected $fillable = ['organization_id', 'student_id', 'academic_session_id', 'title', 'amount', 'due_on', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_on' => 'immutable_date'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FeePayment::class);
    }
}
