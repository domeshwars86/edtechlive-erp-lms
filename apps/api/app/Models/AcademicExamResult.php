<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicExamResult extends Model
{
    protected $fillable = ['organization_id', 'academic_exam_paper_id', 'student_id', 'recorded_by', 'outcome', 'score', 'remarks'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(AcademicExamPaper::class, 'academic_exam_paper_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function sectionResults(): HasMany
    {
        return $this->hasMany(AcademicExamSectionResult::class, 'academic_exam_result_id');
    }
}
