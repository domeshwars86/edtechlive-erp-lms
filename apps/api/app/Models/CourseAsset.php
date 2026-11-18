<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseAsset extends Model
{
    protected $fillable = ['organization_id', 'course_id', 'uploaded_by', 'title', 'original_name', 'storage_path', 'mime_type', 'size_bytes', 'is_published'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'is_published' => 'boolean'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
