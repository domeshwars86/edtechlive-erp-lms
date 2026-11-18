<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\AcademicClass;
use App\Models\AcademicSection;
use App\Models\AcademicSession;
use App\Models\AttendanceRecord;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FamilyTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_read_only_their_linked_profile_and_attendance(): void
    {
        [$user, $organization, $student, $otherStudent, $admin] = $this->family(OrganizationRole::Student);
        $student->update(['user_id' => $user->id]);
        $this->markAttendance($organization->id, $student->id, $admin->id);
        $this->markAttendance($organization->id, $otherStudent->id, $admin->id, 'absent');
        Sanctum::actingAs($user);
        $base = "/api/v1/organizations/{$organization->id}/my";

        $this->getJson("{$base}/student")->assertOk()->assertJsonPath('data.id', $student->id)->assertJsonPath('data.name', $student->name);
        $this->getJson("{$base}/student/attendance")->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.status', 'present')->assertJsonPath('data.0.section.class', 'Grade 6');
        $this->getJson("{$base}/children")->assertForbidden();
    }

    public function test_parent_can_read_only_linked_children_and_their_attendance(): void
    {
        [$parent, $organization, $student, $otherStudent, $admin] = $this->family(OrganizationRole::Parent);
        StudentGuardian::query()->create([
            'organization_id' => $organization->id,
            'student_id' => $student->id,
            'guardian_user_id' => $parent->id,
            'linked_by' => $admin->id,
            'relationship' => 'parent',
            'is_primary' => true,
            'linked_at' => now(),
        ]);
        $this->markAttendance($organization->id, $student->id, $admin->id);
        $this->markAttendance($organization->id, $otherStudent->id, $admin->id, 'absent');
        Sanctum::actingAs($parent);
        $base = "/api/v1/organizations/{$organization->id}/my";

        $this->getJson("{$base}/children")->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $student->id)->assertJsonPath('data.0.is_primary', true);
        $this->getJson("{$base}/children/{$student->id}/attendance")->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status', 'present');
        $this->getJson("{$base}/children/{$otherStudent->id}/attendance")->assertNotFound();
        $this->getJson("/api/v1/organizations/{$this->organization('Other Academy')->id}/my/children")->assertNotFound();
    }

    private function family(OrganizationRole $role): array
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        $organization = $this->organization('Northstar Academy');
        foreach ([[$user, $role], [$admin, OrganizationRole::Admin]] as [$member, $memberRole]) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $member->id, 'role' => $memberRole,
                'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
        $session = AcademicSession::query()->create([
            'organization_id' => $organization->id, 'name' => '2026–2027',
            'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31', 'is_current' => true,
        ]);
        $class = AcademicClass::query()->create(['organization_id' => $organization->id, 'name' => 'Grade 6']);
        $section = AcademicSection::query()->create([
            'organization_id' => $organization->id, 'academic_class_id' => $class->id,
            'academic_session_id' => $session->id, 'name' => 'A',
        ]);
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Student One', 'admission_number' => 'S-100',
            'email' => 'student1@example.com', 'academic_class_id' => $class->id,
            'academic_section_id' => $section->id, 'academic_session_id' => $session->id, 'status' => 'active',
        ]);
        $otherStudent = Student::query()->create([
            'organization_id' => $organization->id, 'name' => 'Student Two', 'admission_number' => 'S-200',
            'email' => 'student2@example.com', 'academic_class_id' => $class->id,
            'academic_section_id' => $section->id, 'academic_session_id' => $session->id, 'status' => 'active',
        ]);

        return [$user, $organization, $student, $otherStudent, $admin];
    }

    private function markAttendance(int $organizationId, int $studentId, int $markedBy, string $status = 'present'): void
    {
        $student = Student::query()->findOrFail($studentId);
        AttendanceRecord::query()->create([
            'organization_id' => $organizationId,
            'academic_section_id' => $student->academic_section_id,
            'academic_session_id' => $student->academic_session_id,
            'student_id' => $student->id,
            'attendance_date' => '2026-11-18',
            'status' => $status,
            'marked_by' => $markedBy,
        ]);
    }

    private function organization(string $name): Organization
    {
        return Organization::query()->create([
            'name' => $name, 'slug' => str()->slug($name).'-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
    }
}
