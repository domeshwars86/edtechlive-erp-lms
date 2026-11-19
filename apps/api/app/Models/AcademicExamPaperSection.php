<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicExamPaperSection extends Model
{
    protected $fillable = ['organization_id', 'academic_exam_paper_id', 'title', 'max_marks', 'position'];

    protected function casts(): array
    {
        return ['max_marks' => 'decimal:2'];
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(AcademicExamPaper::class, 'academic_exam_paper_id');
    }
}
