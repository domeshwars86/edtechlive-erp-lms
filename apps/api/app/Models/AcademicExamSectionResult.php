<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicExamSectionResult extends Model
{
    protected $fillable = ['organization_id', 'academic_exam_result_id', 'academic_exam_paper_section_id', 'score'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2'];
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(AcademicExamResult::class, 'academic_exam_result_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(AcademicExamPaperSection::class, 'academic_exam_paper_section_id');
    }
}
