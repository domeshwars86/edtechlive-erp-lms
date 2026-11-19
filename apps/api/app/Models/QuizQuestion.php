<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    protected $fillable = ['organization_id', 'quiz_id', 'question_bank_question_id', 'prompt', 'options', 'question_type', 'difficulty', 'correct_option', 'correct_options', 'points', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'correct_options' => 'array'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(CourseQuiz::class, 'quiz_id');
    }
}
