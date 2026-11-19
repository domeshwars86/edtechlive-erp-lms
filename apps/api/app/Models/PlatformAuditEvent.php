<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAuditEvent extends Model
{
    protected $fillable = [
        'actor_user_id',
        'organization_id',
        'organization_name',
        'action',
        'reason',
        'before',
        'after',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
