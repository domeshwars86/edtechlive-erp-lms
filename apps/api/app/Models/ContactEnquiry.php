<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactEnquiry extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'organization_name', 'role', 'category', 'message', 'consented_at', 'status', 'notified_at'];

    protected function casts(): array
    {
        return ['consented_at' => 'immutable_datetime', 'notified_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
