<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuizQuestion extends Model
{
    protected $fillable = ['organization_id', 'quiz_id', 'prompt', 'options', 'correct_option', 'points', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(CourseQuiz::class, 'quiz_id');
    }
}
