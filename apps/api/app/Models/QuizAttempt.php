<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizAttempt extends Model
{
    protected $fillable = ['organization_id', 'quiz_id', 'student_id', 'attempt_number', 'answers', 'manual_grades', 'review_status', 'score', 'max_score', 'started_at', 'submitted_at'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'manual_grades' => 'array', 'score' => 'decimal:2', 'max_score' => 'decimal:2', 'started_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(CourseQuiz::class, 'quiz_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
