<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'push_token', 'token_hash', 'platform', 'last_seen_at'])]
class ExpoPushDevice extends Model
{
    protected function casts(): array
    {
        return [
            'push_token' => 'encrypted',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
