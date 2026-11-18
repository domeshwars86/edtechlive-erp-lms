<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeachingAssignment extends Model
{
    protected $fillable = [
        'organization_id', 'academic_session_id', 'academic_section_id', 'subject_id', 'teacher_id',
    ];

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function academicSection(): BelongsTo
    {
        return $this->belongsTo(AcademicSection::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
