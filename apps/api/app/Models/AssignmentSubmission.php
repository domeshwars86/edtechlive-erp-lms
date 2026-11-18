<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentSubmission extends Model
{
    protected $fillable = ['organization_id', 'assignment_id', 'student_id', 'answer', 'submitted_at', 'awarded_points', 'feedback', 'graded_by', 'graded_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime', 'graded_at' => 'immutable_datetime', 'awarded_points' => 'decimal:2'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CourseAssignment::class, 'assignment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
