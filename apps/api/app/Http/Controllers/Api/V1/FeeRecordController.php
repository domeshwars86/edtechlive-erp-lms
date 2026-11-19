<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\FeePayment;
use App\Models\FeeRecord;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\OrganizationAccess;
use App\Services\OrganizationNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FeeRecordController
{
    public function __construct(private readonly OrganizationAccess $access, private readonly OrganizationNotifier $notifier) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $query = FeeRecord::query()->where('organization_id', $organization)->with(['student:id,name,admission_number', 'feeCategory:id,name,code', 'payments']);

        if ($membership->role === OrganizationRole::Student) {
            $student = $this->studentProfile($request, $organization);
            $query->where('student_id', $student->id);
        } elseif ($membership->role === OrganizationRole::Parent) {
            $studentIds = StudentGuardian::query()->where('organization_id', $organization)
                ->where('guardian_user_id', $request->user()->id)
                ->whereHas('student', fn ($students) => $students->where('status', 'active'))
                ->pluck('student_id');
            $query->whereIn('student_id', $studentIds);
        } else {
            $this->assertManager($membership->role);
            if ($request->filled('student_id')) {
                $query->where('student_id', $request->integer('student_id'));
            }
        }

        $filterRules = ['status' => ['sometimes', Rule::in(['unpaid', 'partial', 'paid'])]];
        if (in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true)) {
            $filterRules['student_id'] = ['sometimes', 'integer', Rule::exists('students', 'id')->where('organization_id', $organization)];
        }
        $filters = $request->validate($filterRules);
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $records = $query->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_on')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return response()->json([
            'data' => $records->getCollection()->map(fn (FeeRecord $record): array => $this->feeData($record))->values(),
            'meta' => ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $records->perPage(), 'total' => $records->total()],
        ]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization, true);
        $this->assertManager($membership->role);
        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->where('organization_id', $organization)->where('status', 'active')],
            'academic_session_id' => ['nullable', 'integer', Rule::exists('academic_sessions', 'id')->where('organization_id', $organization)],
            'fee_category_id' => ['nullable', 'integer', Rule::exists('fee_categories', 'id')->where('organization_id', $organization)->where('is_active', true)],
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $record = FeeRecord::query()->create([
            ...$data,
            'organization_id' => $organization,
            'status' => 'unpaid',
            'created_by' => $request->user()->id,
        ]);
        $this->notifier->feeIssued($record, $request->user()->id);

        return response()->json(['data' => $this->feeData($record->load(['student:id,name,admission_number', 'feeCategory:id,name,code', 'payments']))], 201);
    }

    public function payment(Request $request, int $organization, int $fee): JsonResponse
    {
        $membership = $this->access->membership($request, $organization, true);
        $this->assertManager($membership->role);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'method' => ['required', Rule::in(['cash', 'bank_transfer', 'upi', 'other'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'received_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ]);

        $record = DB::transaction(function () use ($data, $organization, $fee, $request): FeeRecord {
            $record = FeeRecord::query()->where('organization_id', $organization)->lockForUpdate()->findOrFail($fee);
            abort_if($record->status === 'paid', 409, 'This fee has already been settled.');
            $paidCents = (int) round((float) $record->payments()->sum('amount') * 100);
            $amountCents = (int) round((float) $data['amount'] * 100);
            $totalCents = (int) round((float) $record->amount * 100);
            if ($paidCents + $amountCents > $totalCents) {
                throw ValidationException::withMessages(['amount' => ['The payment cannot exceed the remaining balance.']]);
            }

            FeePayment::query()->create([
                'organization_id' => $organization,
                'fee_record_id' => $record->id,
                'amount' => number_format($amountCents / 100, 2, '.', ''),
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'received_by' => $request->user()->id,
                'received_at' => $data['received_at'] ?? now(),
            ]);
            $record->update(['status' => $paidCents + $amountCents === $totalCents ? 'paid' : 'partial']);

            return $record->refresh()->load(['student:id,name,admission_number', 'feeCategory:id,name,code', 'payments']);
        });
        $this->notifier->feePaymentRecorded($record, $request->user()->id, number_format((int) round((float) $data['amount'] * 100) / 100, 2, '.', ''));

        return response()->json(['data' => $this->feeData($record)]);
    }

    private function feeData(FeeRecord $record): array
    {
        $paidCents = (int) round((float) $record->payments->sum(fn (FeePayment $payment): float => (float) $payment->amount) * 100);
        $amountCents = (int) round((float) $record->amount * 100);

        return [
            'id' => $record->id,
            'student' => $record->student?->only(['id', 'name', 'admission_number']),
            'title' => $record->title,
            'category' => $record->feeCategory?->only(['id', 'name', 'code']),
            'amount' => number_format($amountCents / 100, 2, '.', ''),
            'paid_amount' => number_format($paidCents / 100, 2, '.', ''),
            'balance' => number_format(max(0, $amountCents - $paidCents) / 100, 2, '.', ''),
            'due_on' => $record->due_on?->format('Y-m-d'),
            'status' => $record->status,
            'payments' => $record->payments->map(fn (FeePayment $payment): array => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'received_at' => $payment->received_at?->toISOString(),
            ])->values(),
        ];
    }

    private function assertManager(OrganizationRole $role): void
    {
        abort_unless(in_array($role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);
    }

    private function studentProfile(Request $request, int $organization): Student
    {
        return Student::query()->where('organization_id', $organization)->where('user_id', $request->user()->id)
            ->where('status', 'active')->firstOrFail();
    }
}
