<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentGuardian extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'guardian_user_id', 'linked_by', 'relationship', 'is_primary', 'linked_at',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'linked_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }
}
