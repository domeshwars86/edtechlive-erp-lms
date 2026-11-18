<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $fillable = ['organization_id', 'name', 'employee_number', 'email', 'phone', 'specialization', 'status'];
}
