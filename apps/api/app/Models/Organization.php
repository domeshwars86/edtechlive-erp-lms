<?php

namespace App\Models;

use App\Enums\OrganizationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'type',
        'status',
        'timezone',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'type' => OrganizationType::class,
            'settings' => 'array',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['role', 'status', 'invited_by', 'accepted_at', 'expires_at'])
            ->withTimestamps();
    }

    public function academicSessions(): HasMany
    {
        return $this->hasMany(AcademicSession::class);
    }

    public function academicClasses(): HasMany
    {
        return $this->hasMany(AcademicClass::class);
    }

    public function academicSections(): HasMany
    {
        return $this->hasMany(AcademicSection::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(Subject::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }

    public function studentGuardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function timetableEntries(): HasMany
    {
        return $this->hasMany(TimetableEntry::class);
    }
}
