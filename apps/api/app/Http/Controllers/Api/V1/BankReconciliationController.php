<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\FeePayment;
use App\Models\FinanceExpense;
use App\Services\OrganizationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BankReconciliationController
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function index(Request $request, int $organization): JsonResponse
    {
        $membership = $this->manager($request, $organization);
        $statements = BankStatement::query()->where('organization_id', $organization)
            ->with(['lines.feePayment.feeRecord', 'lines.expense'])
            ->withCount(['lines as matched_count' => fn ($query) => $query->where(fn ($q) => $q->whereNotNull('fee_payment_id')->orWhereNotNull('finance_expense_id'))])
            ->orderByDesc('period_to')->orderByDesc('id')->limit(30)->get();

        return response()->json(['data' => $statements->map(fn (BankStatement $statement): array => [
            'id' => $statement->id,
            'account_label' => $statement->account_label,
            'period_from' => $statement->period_from->toDateString(),
            'period_to' => $statement->period_to->toDateString(),
            'opening_balance' => $statement->opening_balance,
            'closing_balance' => $statement->closing_balance,
            'line_count' => $statement->line_count,
            'matched_count' => (int) $statement->matched_count,
            'lines' => $statement->lines->map(fn (BankStatementLine $line): array => $this->line($line, $membership->organization->timezone))->values(),
        ])->values()]);
    }

    public function store(Request $request, int $organization): JsonResponse
    {
        $membership = $this->manager($request, $organization);
        $data = $request->validate([
            'account_label' => ['required', 'string', 'min:2', 'max:120'],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:'.CarbonImmutable::now($membership->organization->timezone)->toDateString()],
            'opening_balance' => ['required', 'numeric', 'min:-9999999999.99', 'max:9999999999.99', 'decimal:0,2'],
            'closing_balance' => ['required', 'numeric', 'min:-9999999999.99', 'max:9999999999.99', 'decimal:0,2'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.transacted_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:period_to'],
            'lines.*.description' => ['required', 'string', 'min:2', 'max:240'],
            'lines.*.direction' => ['required', Rule::in(['credit', 'debit'])],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
            'lines.*.reference' => ['nullable', 'string', 'max:120'],
        ]);

        $balance = $this->cents($data['opening_balance']);
        foreach ($data['lines'] as $line) {
            $balance += ($line['direction'] === 'credit' ? 1 : -1) * $this->cents($line['amount']);
        }
        if ($balance !== $this->cents($data['closing_balance'])) {
            throw ValidationException::withMessages(['closing_balance' => ['The closing balance must equal the opening balance plus credits minus debits in the imported lines.']]);
        }

        $canonicalLines = array_map(fn (array $line): array => [
            $line['transacted_on'], trim($line['description']), $line['direction'], $this->cents($line['amount']), trim($line['reference'] ?? ''),
        ], $data['lines']);
        sort($canonicalLines);
        $fingerprint = hash('sha256', json_encode([
            mb_strtolower(trim($data['account_label'])), $data['period_from'], $data['period_to'],
            $this->cents($data['opening_balance']), $this->cents($data['closing_balance']), $canonicalLines,
        ], JSON_THROW_ON_ERROR));
        abort_if(BankStatement::query()->where('organization_id', $organization)->where('fingerprint', $fingerprint)->exists(), 409, 'This statement has already been imported.');

        try {
            $statement = DB::transaction(function () use ($data, $organization, $request, $fingerprint): BankStatement {
                $statement = BankStatement::query()->create([
                    'organization_id' => $organization,
                    'created_by' => $request->user()->id,
                    'account_label' => trim($data['account_label']),
                    'fingerprint' => $fingerprint,
                    'period_from' => $data['period_from'],
                    'period_to' => $data['period_to'],
                    'opening_balance' => $data['opening_balance'],
                    'closing_balance' => $data['closing_balance'],
                    'line_count' => count($data['lines']),
                ]);
                foreach ($data['lines'] as $line) {
                    $statement->lines()->create([...$line, 'description' => trim($line['description'])]);
                }

                return $statement;
            });
        } catch (UniqueConstraintViolationException $exception) {
            abort(409, 'This statement has already been imported.');
        } catch (QueryException $exception) {
            throw $exception;
        }

        return response()->json(['data' => ['id' => $statement->id, 'line_count' => $statement->line_count]], 201);
    }

    public function match(Request $request, int $organization, int $line): JsonResponse
    {
        $membership = $this->manager($request, $organization);
        $data = $request->validate([
            'ledger_type' => ['required', Rule::in(['fee_payment', 'expense'])],
            'ledger_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $matched = DB::transaction(function () use ($organization, $line, $data, $membership): BankStatementLine {
                $statementLine = BankStatementLine::query()->whereKey($line)
                    ->whereHas('statement', fn ($query) => $query->where('organization_id', $organization))
                    ->lockForUpdate()->firstOrFail();
                abort_if($statementLine->fee_payment_id || $statementLine->finance_expense_id, 409, 'This statement line has already been reconciled.');

                if ($data['ledger_type'] === 'fee_payment') {
                    abort_unless($statementLine->direction === 'credit', 422, 'Credit statement lines can only match fee receipts.');
                    $payment = FeePayment::query()->whereKey($data['ledger_id'])->where('organization_id', $organization)->lockForUpdate()->firstOrFail();
                    $paymentDate = CarbonImmutable::parse($payment->received_at)->setTimezone($membership->organization->timezone)->toDateString();
                    abort_unless($this->cents($statementLine->amount) === $this->cents($payment->amount), 422, 'The statement and ledger amounts must match exactly.');
                    abort_unless(abs(CarbonImmutable::parse($statementLine->transacted_on->toDateString())->diffInDays($paymentDate, false)) <= 7, 422, 'The ledger date must be within seven days of the statement date.');
                    abort_if(BankStatementLine::query()->where('fee_payment_id', $payment->id)->exists(), 409, 'This ledger receipt is already reconciled.');
                    $statementLine->fee_payment_id = $payment->id;
                } else {
                    abort_unless($statementLine->direction === 'debit', 422, 'Debit statement lines can only match expenses.');
                    $expense = FinanceExpense::query()->whereKey($data['ledger_id'])->where('organization_id', $organization)->lockForUpdate()->firstOrFail();
                    abort_unless($this->cents($statementLine->amount) === $this->cents($expense->amount), 422, 'The statement and ledger amounts must match exactly.');
                    abort_unless(abs(CarbonImmutable::parse($statementLine->transacted_on->toDateString())->diffInDays($expense->incurred_on->toDateString(), false)) <= 7, 422, 'The ledger date must be within seven days of the statement date.');
                    abort_if(BankStatementLine::query()->where('finance_expense_id', $expense->id)->exists(), 409, 'This ledger expense is already reconciled.');
                    $statementLine->finance_expense_id = $expense->id;
                }
                $statementLine->save();

                return $statementLine->load(['feePayment.feeRecord', 'expense']);
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                abort(409, 'This ledger entry is already reconciled.');
            }
            throw $exception;
        }

        return response()->json(['data' => $this->line($matched, $membership->organization->timezone)]);
    }

    public function candidates(Request $request, int $organization, int $line): JsonResponse
    {
        $membership = $this->manager($request, $organization);
        $statementLine = BankStatementLine::query()->whereKey($line)
            ->whereHas('statement', fn ($query) => $query->where('organization_id', $organization))->firstOrFail();
        $date = CarbonImmutable::parse($statementLine->transacted_on->toDateString(), $membership->organization->timezone);
        $from = $date->subDays(7)->startOfDay();
        $to = $date->addDays(7)->endOfDay();
        $matchedPaymentIds = BankStatementLine::query()->whereNotNull('fee_payment_id')->pluck('fee_payment_id');
        $matchedExpenseIds = BankStatementLine::query()->whereNotNull('finance_expense_id')->pluck('finance_expense_id');
        $candidates = $statementLine->direction === 'credit'
            ? FeePayment::query()->where('organization_id', $organization)->where('amount', $statementLine->amount)
                ->whereBetween('received_at', [$from->utc(), $to->utc()])->whereNotIn('id', $matchedPaymentIds)
                ->with('feeRecord:id,title')->orderBy('received_at')->limit(50)->get()->map(fn (FeePayment $payment): array => [
                    'type' => 'fee_payment', 'id' => $payment->id, 'amount' => $payment->amount,
                    'date' => CarbonImmutable::parse($payment->received_at)->setTimezone($membership->organization->timezone)->toDateString(),
                    'description' => $payment->feeRecord->title,
                ])->values()
            : FinanceExpense::query()->where('organization_id', $organization)->where('amount', $statementLine->amount)
                ->whereBetween('incurred_on', [$from->toDateString(), $to->toDateString()])->whereNotIn('id', $matchedExpenseIds)
                ->orderBy('incurred_on')->limit(50)->get()->map(fn (FinanceExpense $expense): array => [
                    'type' => 'expense', 'id' => $expense->id, 'amount' => $expense->amount,
                    'date' => $expense->incurred_on->toDateString(), 'description' => $expense->title,
                ])->values();

        return response()->json(['data' => $candidates]);
    }

    public function unmatch(Request $request, int $organization, int $line): JsonResponse
    {
        $membership = $this->manager($request, $organization);
        $statementLine = BankStatementLine::query()->whereKey($line)
            ->whereHas('statement', fn ($query) => $query->where('organization_id', $organization))->firstOrFail();
        $statementLine->fee_payment_id = null;
        $statementLine->finance_expense_id = null;
        $statementLine->save();

        return response()->json(['data' => $this->line($statementLine->load(['feePayment.feeRecord', 'expense']), $membership->organization->timezone)]);
    }

    private function manager(Request $request, int $organization)
    {
        $membership = $this->access->membership($request, $organization);
        abort_unless(in_array($membership->role, [OrganizationRole::Admin, OrganizationRole::Management, OrganizationRole::IndependentEducator], true), 403);

        return $membership;
    }

    private function cents(string|int|float $amount): int
    {
        $value = (string) $amount;
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function line(BankStatementLine $line, string $timezone): array
    {
        $matched = $line->feePayment ?? $line->expense;

        return [
            'id' => $line->id,
            'transacted_on' => $line->transacted_on->toDateString(),
            'description' => $line->description,
            'direction' => $line->direction,
            'amount' => $line->amount,
            'reference' => $line->reference,
            'status' => $matched ? 'matched' : 'unmatched',
            'match' => $line->fee_payment_id ? [
                'type' => 'fee_payment', 'id' => $line->feePayment->id, 'amount' => $line->feePayment->amount,
                'date' => CarbonImmutable::parse($line->feePayment->received_at)->setTimezone($timezone)->toDateString(),
                'description' => $line->feePayment->feeRecord->title,
            ] : ($line->expense ? [
                'type' => 'expense', 'id' => $line->expense->id, 'amount' => $line->expense->amount,
                'date' => $line->expense->incurred_on->toDateString(), 'description' => $line->expense->title,
            ] : null),
        ];
    }
}
