<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'kind', 'title', 'body', 'status', 'attempts',
        'queued_at', 'sent_at', 'failed_at', 'cancelled_at', 'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
