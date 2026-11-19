<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicBatch extends Model
{
    protected $fillable = ['organization_id', 'academic_session_id', 'academic_class_id', 'academic_section_id', 'name', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function academicClass(): BelongsTo
    {
        return $this->belongsTo(AcademicClass::class);
    }

    public function academicSection(): BelongsTo
    {
        return $this->belongsTo(AcademicSection::class);
    }
}
