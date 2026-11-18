<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseLesson extends Model
{
    protected $fillable = ['organization_id', 'course_id', 'title', 'sort_order', 'content', 'external_url', 'status'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
