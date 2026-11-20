<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAssistanceUsage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'provider',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
