<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\FeePayment;
use App\Models\FeeRecord;
use App\Models\FinanceExpense;
use App\Services\OrganizationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

        $timezone = $membership->organization->timezone;
        $today = CarbonImmutable::now($timezone)->toDateString();
        $filters = $request->validate([
            'from' => ['sometimes', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $periodEnd = isset($filters['to'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $timezone)->endOfDay()
            : CarbonImmutable::now($timezone)->endOfDay();
        $periodStart = isset($filters['from'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone)->startOfDay()
            : $periodEnd->startOfDay()->subDays(29);
        if ($periodStart->startOfDay()->diffInDays($periodEnd->startOfDay()) > 365) {
            throw ValidationException::withMessages(['to' => ['The receipt date range cannot exceed 366 calendar days.']]);
        }
        $records = FeeRecord::query()->where('organization_id', $organization)
            ->selectRaw('COUNT(*) as record_count')
            ->selectRaw('COALESCE(SUM(amount), 0) as issued_amount')
            ->selectRaw("SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count")
            ->selectRaw("SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_count")
            ->selectRaw("SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count")
            ->first();

        $received = FeePayment::query()->where('organization_id', $organization)->sum('amount');
        $periodReceipts = FeePayment::query()->where('organization_id', $organization)
            ->whereBetween('received_at', [$periodStart->utc(), $periodEnd->utc()])
            ->selectRaw('COUNT(*) as receipt_count')->selectRaw('COALESCE(SUM(amount), 0) as received_amount')->first();
        $expenses = FinanceExpense::query()->where('organization_id', $organization);
        $totalExpenses = (int) round((float) (clone $expenses)->sum('amount') * 100);
        $periodExpenses = (clone $expenses)->whereDate('incurred_on', '>=', $periodStart->toDateString())
            ->whereDate('incurred_on', '<=', $periodEnd->toDateString())
            ->selectRaw('COUNT(*) as expense_count')->selectRaw('COALESCE(SUM(amount), 0) as amount')->first();
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
        $periodReceiptCents = (int) round((float) $periodReceipts->received_amount * 100);
        $periodExpenseCents = (int) round((float) $periodExpenses->amount * 100);

        return response()->json(['data' => [
            'as_of' => $today,
            'currency' => 'INR',
            'collections_period' => [
                'from' => $periodStart->toDateString(),
                'to' => $periodEnd->toDateString(),
                'receipt_count' => (int) $periodReceipts->receipt_count,
                'received_amount' => number_format($periodReceiptCents / 100, 2, '.', ''),
                'expense_count' => (int) $periodExpenses->expense_count,
                'expense_amount' => number_format($periodExpenseCents / 100, 2, '.', ''),
                'net_cash_flow' => number_format(($periodReceiptCents - $periodExpenseCents) / 100, 2, '.', ''),
            ],
            'summary' => [
                'fee_records' => (int) $records->record_count,
                'unpaid_records' => (int) $records->unpaid_count,
                'partial_records' => (int) $records->partial_count,
                'paid_records' => (int) $records->paid_count,
                'issued_amount' => number_format($issuedCents / 100, 2, '.', ''),
                'received_amount' => number_format($receivedCents / 100, 2, '.', ''),
                'expense_amount' => number_format($totalExpenses / 100, 2, '.', ''),
                'net_cash_flow' => number_format(($receivedCents - $totalExpenses) / 100, 2, '.', ''),
                'outstanding_amount' => number_format($outstandingCents / 100, 2, '.', ''),
                'overdue_records' => (int) $overdue->record_count,
                'overdue_balance' => number_format($overdueCents / 100, 2, '.', ''),
            ],
        ]]);
    }
}
