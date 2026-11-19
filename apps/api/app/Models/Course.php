<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Course extends Model
{
    protected $fillable = ['organization_id', 'subject_id', 'teacher_id', 'created_by', 'title', 'level', 'description', 'status', 'published_at', 'is_public_listing'];

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'is_public_listing' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (Course $course): void {
            if ($course->slug) {
                return;
            }

            $base = Str::slug($course->title) ?: 'course';
            $slug = $base;
            $suffix = 2;
            while (static::query()->where('organization_id', $course->organization_id)->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$suffix++;
            }
            $course->slug = $slug;
        });
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(CourseLesson::class)->orderBy('sort_order')->orderBy('id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order')->orderBy('id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CourseEnrollment::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CourseAssignment::class);
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(CourseQuiz::class);
    }

    public function questionBanks(): HasMany
    {
        return $this->hasMany(CourseQuestionBank::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(CourseAsset::class);
    }
}
