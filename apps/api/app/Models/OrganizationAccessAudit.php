<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationAccessAudit extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'membership_id', 'actor_user_id', 'subject_user_id',
        'previous_role', 'role', 'previous_status', 'status', 'created_at',
        'event_type', 'previous_permissions', 'permissions',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'previous_permissions' => 'array',
            'permissions' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }
}
