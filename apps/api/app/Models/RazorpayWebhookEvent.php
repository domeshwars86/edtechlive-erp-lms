<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RazorpayWebhookEvent extends Model
{
    protected $fillable = ['event_id', 'event_type', 'body_sha256', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }
}
