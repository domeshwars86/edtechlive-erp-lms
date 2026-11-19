<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseQuestionBankQuestion extends Model
{
    protected $fillable = ['organization_id', 'question_bank_id', 'created_by', 'prompt', 'options', 'question_type', 'difficulty', 'correct_options', 'points', 'sort_order'];

    protected function casts(): array
    {
        return ['options' => 'array', 'correct_options' => 'array'];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(CourseQuestionBank::class, 'question_bank_id');
    }
}
