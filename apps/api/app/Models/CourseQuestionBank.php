<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CourseQuestionBank extends Model
{
    protected $fillable = ['organization_id', 'course_id', 'created_by', 'title', 'description'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(CourseQuestionBankQuestion::class, 'question_bank_id')->orderBy('sort_order')->orderBy('id');
    }
}
