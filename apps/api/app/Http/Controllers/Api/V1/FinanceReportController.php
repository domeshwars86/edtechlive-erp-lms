<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\FeePayment;
use App\Models\FeeRecord;
use App\Services\OrganizationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceReportController
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

        $today = CarbonImmutable::now($membership->organization->timezone)->toDateString();
        $records = FeeRecord::query()->where('organization_id', $organization)
            ->selectRaw('COUNT(*) as record_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as issued_amount')
            ->selectRaw("SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count")
            ->selectRaw("SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_count")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count")
            ->first();

        $received = FeePayment::query()->where('organization_id', $organization)->sum('amount');
        $paymentTotals = FeePayment::query()->where('organization_id', $organization)
            ->selectRaw('fee_record_id, SUM(amount) as paid_amount')->groupBy('fee_record_id');
        $overdue = FeeRecord::query()->where('fee_records.organization_id', $organization)
            ->leftJoinSub($paymentTotals, 'payment_totals', fn ($join) => $join->on('payment_totals.fee_record_id', '=', 'fee_records.id'))
            ->whereNotNull('fee_records.due_on')->whereDate('fee_records.due_on', '<', $today)
            ->whereIn('fee_records.status', ['unpaid', 'partial'])
            ->selectRaw('COUNT(*) as record_count')
            ->selectRaw('COALESCE(SUM(fee_records.amount - COALESCE(payment_totals.paid_amount, 0)), 0) as balance')
            ->first();

        $issuedCents = (int) round((float) $records->issued_amount * 100);
        $receivedCents = (int) round((float) $received * 100);
        $outstandingCents = max(0, $issuedCents - $receivedCents);
        $overdueCents = max(0, (int) round((float) $overdue->balance * 100));

        return response()->json(['data' => [
            'as_of' => $today,
            'currency' => 'INR',
            'summary' => [
                'fee_records' => (int) $records->record_count,
                'unpaid_records' => (int) $records->unpaid_count,
                'partial_records' => (int) $records->partial_count,
                'paid_records' => (int) $records->paid_count,
                'issued_amount' => number_format($issuedCents / 100, 2, '.', ''),
                'received_amount' => number_format($receivedCents / 100, 2, '.', ''),
                'outstanding_amount' => number_format($outstandingCents / 100, 2, '.', ''),
                'overdue_records' => (int) $overdue->record_count,
                'overdue_balance' => number_format($overdueCents / 100, 2, '.', ''),
            ],
        ]]);
    }
}
