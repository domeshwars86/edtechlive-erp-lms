<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Teacher extends Model
{
    protected $fillable = ['organization_id', 'user_id', 'name', 'employee_number', 'email', 'phone', 'specialization', 'status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
