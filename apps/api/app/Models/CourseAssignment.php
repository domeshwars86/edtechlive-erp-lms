<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseAssignment extends Model
{
    protected $fillable = ['organization_id', 'course_id', 'created_by', 'title', 'instructions', 'due_at', 'max_points', 'status'];

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'max_points' => 'decimal:2'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(AssignmentSubmission::class, 'assignment_id');
    }
}
