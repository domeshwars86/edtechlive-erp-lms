<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\FinanceExpense;
use App\Services\OrganizationFinanceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceExpenseController
{
    public function __construct(private readonly OrganizationFinanceAccess $financeAccess) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $this->financeAccess->read($request, $organization);
        $data = $request->validate([
            'from' => ['sometimes', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = FinanceExpense::query()->where('organization_id', $organization);
        if (isset($data['from'])) {
            $query->whereDate('incurred_on', '>=', $data['from']);
        }
        if (isset($data['to'])) {
            $query->whereDate('incurred_on', '<=', $data['to']);
        }
        $expenses = $query->orderByDesc('incurred_on')->orderByDesc('id')->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return response()->json([
            'data' => $expenses->getCollection()->map(fn (FinanceExpense $expense): array => $this->row($expense))->values(),
            'meta' => ['current_page' => $expenses->currentPage(), 'last_page' => $expenses->lastPage(), 'per_page' => $expenses->perPage(), 'total' => $expenses->total()],
        ]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $membership = $this->financeAccess->manage($request, $organization);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:2', 'max:160'],
            'category' => ['required', Rule::in(['salaries', 'utilities', 'supplies', 'maintenance', 'transport', 'technology', 'other'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'incurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now($membership->organization->timezone)->toDateString()],
            'payment_method' => ['required', Rule::in(['cash', 'bank_transfer', 'card', 'upi', 'other'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $expense = FinanceExpense::query()->create([...$data, 'organization_id' => $organization, 'recorded_by' => $request->user()->id]);

        return response()->json(['data' => $this->row($expense)], 201);
    }

    private function row(FinanceExpense $expense): array
    {
        return [
            'id' => $expense->id, 'title' => $expense->title, 'category' => $expense->category,
            'amount' => $expense->amount, 'incurred_on' => $expense->incurred_on->toDateString(),
            'payment_method' => $expense->payment_method, 'reference' => $expense->reference, 'notes' => $expense->notes,
        ];
    }
}
