<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseLessonProgress extends Model
{
    protected $fillable = ['organization_id', 'course_id', 'lesson_id', 'student_id', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(CourseLesson::class);
    }
}
