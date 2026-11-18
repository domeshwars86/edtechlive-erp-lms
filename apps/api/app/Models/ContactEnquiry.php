<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactEnquiry extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'organization_name', 'role', 'category', 'message', 'consented_at', 'status', 'notified_at'];

    protected function casts(): array
    {
        return ['consented_at' => 'immutable_datetime', 'notified_at' => 'immutable_datetime'];
    }
}
