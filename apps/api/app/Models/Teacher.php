<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Teacher extends Model
{
    protected $fillable = ['organization_id', 'organization_branch_id', 'user_id', 'name', 'employee_number', 'email', 'phone', 'specialization', 'status'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(OrganizationBranch::class, 'organization_branch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
