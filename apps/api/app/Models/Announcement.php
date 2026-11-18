<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    protected $fillable = ['organization_id', 'created_by', 'title', 'body', 'audience', 'recipient_count', 'sent_at'];

    protected function casts(): array
    {
        return ['recipient_count' => 'integer', 'sent_at' => 'immutable_datetime'];
    }
}
