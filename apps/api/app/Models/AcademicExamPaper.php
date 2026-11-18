<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicExamPaper extends Model
{
    protected $fillable = ['organization_id', 'academic_exam_id', 'academic_section_id', 'subject_id', 'exam_on', 'max_marks'];

    protected function casts(): array
    {
        return ['exam_on' => 'immutable_date', 'max_marks' => 'decimal:2'];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(AcademicExam::class, 'academic_exam_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(AcademicSection::class, 'academic_section_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(AcademicExamResult::class, 'academic_exam_paper_id');
    }
}
