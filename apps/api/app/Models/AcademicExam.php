<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicExam extends Model
{
    protected $fillable = ['organization_id', 'academic_session_id', 'created_by', 'title', 'series_name', 'starts_on', 'ends_on', 'status', 'published_at'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'published_at' => 'immutable_datetime'];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function papers(): HasMany
    {
        return $this->hasMany(AcademicExamPaper::class);
    }
}
