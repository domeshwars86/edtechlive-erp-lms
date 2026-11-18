<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\AttendanceRecord;
use App\Services\OrganizationAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AttendanceReportController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless(in_array($membership->role, [
            OrganizationRole::Admin,
            OrganizationRole::Management,
            OrganizationRole::IndependentEducator,
        ], true), 403);

        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'session_id' => ['sometimes', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
        ]);
        if ($filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => ['The end date must be on or after the start date.']]);
        }

        $query = AttendanceRecord::query()->where('organization_id', $organization)
            ->whereDate('attendance_date', '>=', $filters['from'])
            ->whereDate('attendance_date', '<=', $filters['to']);
        if (isset($filters['session_id'])) {
            $query->where('academic_session_id', $filters['session_id']);
        }

        $records = (clone $query)->with(['academicSection:id,name,academic_class_id', 'academicSection.academicClass:id,name'])
            ->orderBy('academic_section_id')->orderBy('attendance_date')->get();
        $groups = $records->groupBy(fn (AttendanceRecord $record): int => $record->academic_section_id)
            ->map(function ($sectionRecords): array {
                $section = $sectionRecords->first()->academicSection;
                $counts = $sectionRecords->countBy('status');
                $total = $sectionRecords->count();
                $present = ($counts->get('present', 0) + $counts->get('late', 0));

                return [
                    'section_id' => $section->id,
                    'class' => $section->academicClass->name,
                    'section' => $section->name,
                    'records' => $total,
                    'present' => $counts->get('present', 0),
                    'late' => $counts->get('late', 0),
                    'absent' => $counts->get('absent', 0),
                    'excused' => $counts->get('excused', 0),
                    'attendance_rate' => $total > 0 ? round($present / $total * 100, 1) : 0,
                ];
            })->values();
        $totals = $records->countBy('status');
        $totalRecords = $records->count();

        return response()->json([
            'data' => [
                'from' => $filters['from'],
                'to' => $filters['to'],
                'totals' => [
                    'records' => $totalRecords,
                    'present' => $totals->get('present', 0),
                    'late' => $totals->get('late', 0),
                    'absent' => $totals->get('absent', 0),
                    'excused' => $totals->get('excused', 0),
                    'attendance_rate' => $totalRecords > 0
                        ? round(($totals->get('present', 0) + $totals->get('late', 0)) / $totalRecords * 100, 1)
                        : 0,
                ],
                'sections' => $groups,
            ],
        ]);
    }
}
