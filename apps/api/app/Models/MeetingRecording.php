<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingRecording extends Model
{
    protected $fillable = [
        'organization_id', 'meeting_id', 'uploaded_by', 'title', 'description', 'original_name',
        'storage_path', 'mime_type', 'size_bytes', 'external_url', 'status', 'consent_confirmed_at',
        'consent_confirmed_by', 'withdrawn_at',
    ];

    protected function casts(): array
    {
        return ['consent_confirmed_at' => 'immutable_datetime', 'withdrawn_at' => 'immutable_datetime'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
