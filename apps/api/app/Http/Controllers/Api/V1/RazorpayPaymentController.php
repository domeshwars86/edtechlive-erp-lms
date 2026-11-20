<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganizationRole;
use App\Models\FeePayment;
use App\Models\FeeRecord;
use App\Models\RazorpayPaymentOrder;
use App\Models\RazorpayWebhookEvent;
use App\Models\StudentGuardian;
use App\Services\OrganizationAccess;
use App\Services\OrganizationNotifier;
use App\Services\RazorpayGateway;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RazorpayPaymentController
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly OrganizationNotifier $notifier,
        private readonly RazorpayGateway $gateway,
    ) {}

    public function config(Request $request, int $organization): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $this->assertPayerRole($membership->role);

        return response()->json(['data' => [
            'enabled' => $this->gateway->enabled(),
            'key_id' => $this->gateway->enabled() ? $this->gateway->keyId() : null,
            'brand_name' => config('services.razorpay.brand_name', 'edTechLive'),
            'currency' => 'INR',
            'mode' => $this->gateway->enabled() ? 'test' : 'disabled',
        ]]);
    }

    public function start(Request $request, int $organization, int $fee): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $this->assertPayerRole($membership->role);
        abort_unless($this->gateway->enabled(), 503, 'Online payments are unavailable until Razorpay Test Mode is configured.');

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || preg_match('/\A[A-Za-z0-9._~-]{16,128}\z/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['Provide an Idempotency-Key header containing 16–128 letters, digits, dots, underscores, tildes, or hyphens.']]);
        }

        $payerId = (int) $request->user()->id;
        $result = DB::transaction(function () use ($organization, $fee, $membership, $payerId, $idempotencyKey): array {
            $record = FeeRecord::query()->where('organization_id', $organization)->lockForUpdate()->findOrFail($fee);
            $this->assertMayPay($record, $membership->role, $payerId);

            $existing = RazorpayPaymentOrder::query()->where('organization_id', $organization)
                ->where('payer_user_id', $payerId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                abort_if((int) $existing->fee_record_id !== $fee, 409, 'This Idempotency-Key was already used for another fee.');
                abort_if($existing->status === 'creating', 409, 'The payment order is still being created. Retry shortly.');
                if ($existing->status === 'failed') {
                    abort_if($record->status === 'paid', 409, 'This fee has already been settled.');
                    $balance = $this->balancePaise($record);
                    abort_if($balance < 100, 409, 'The remaining balance is below Razorpay’s ₹1 minimum.');
                    abort_if($balance !== $existing->amount_paise, 409, 'The fee balance changed. Start a new checkout attempt.');
                    $existing->update([
                        'receipt' => 'retry-'.Str::lower(Str::random(20)),
                        'status' => 'creating',
                    ]);

                    return ['order' => $existing->refresh(), 'create' => true];
                }

                return ['order' => $existing, 'create' => false];
            }

            abort_if($record->status === 'paid', 409, 'This fee has already been settled.');

            $openOrder = RazorpayPaymentOrder::query()->where('organization_id', $organization)
                ->where('fee_record_id', $record->id)->whereIn('status', ['creating', 'created'])->first();
            abort_if($openOrder !== null, 409, 'A checkout is already in progress for this fee. Reopen it from the fee record.');

            $balance = $this->balancePaise($record);
            abort_if($balance < 100, 409, 'The remaining balance is below Razorpay’s ₹1 minimum.');

            $order = RazorpayPaymentOrder::query()->create([
                'organization_id' => $organization,
                'fee_record_id' => $record->id,
                'payer_user_id' => $payerId,
                'receipt' => 'pending-'.Str::lower(Str::random(20)),
                'idempotency_key' => $idempotencyKey,
                'amount_paise' => $balance,
                'currency' => 'INR',
                'status' => 'creating',
            ]);
            $order->update(['receipt' => 'e'.dechex((int) $order->id).'-'.Str::lower(Str::random(20))]);

            return ['order' => $order->refresh(), 'create' => true];
        });

        /** @var RazorpayPaymentOrder $order */
        $order = $result['order'];
        if ($result['create']) {
            try {
                $gatewayOrder = $this->gateway->createOrder($order->amount_paise, $order->receipt, [
                    'organization_id' => (string) $order->organization_id,
                    'fee_record_id' => (string) $order->fee_record_id,
                ]);
                if ($gatewayOrder['amount'] !== $order->amount_paise || $gatewayOrder['currency'] !== 'INR') {
                    $order->update(['status' => 'failed']);
                    abort(502, 'Razorpay returned an order that does not match the fee balance.');
                }
                $order->update(['gateway_order_id' => $gatewayOrder['id'], 'status' => 'created']);
            } catch (Throwable $exception) {
                $order->update(['status' => 'failed']);
                if ($exception instanceof HttpExceptionInterface) {
                    throw $exception;
                }

                abort(502, 'Razorpay could not create the test payment order. No payment has been recorded.');
            }
        }

        return response()->json(['data' => [
            'order_id' => $order->gateway_order_id,
            'amount' => $order->amount_paise,
            'currency' => $order->currency,
            'key_id' => $this->gateway->keyId(),
            'brand_name' => config('services.razorpay.brand_name', 'edTechLive'),
            'status' => $order->status,
        ]], $result['create'] ? 201 : 200);
    }

    public function verify(Request $request, int $organization, int $fee): JsonResponse
    {
        $membership = $this->access->membership($request, $organization);
        $this->assertPayerRole($membership->role);
        abort_unless($this->gateway->enabled(), 503, 'Online payments are unavailable until Razorpay Test Mode is configured.');
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:80'],
            'razorpay_payment_id' => ['required', 'string', 'max:80'],
            'razorpay_signature' => ['required', 'string', 'size:64'],
        ]);

        $order = RazorpayPaymentOrder::query()->where('organization_id', $organization)
            ->where('fee_record_id', $fee)->where('payer_user_id', $request->user()->id)
            ->where('gateway_order_id', $data['razorpay_order_id'])->firstOrFail();
        abort_unless($this->gateway->verifyCheckoutSignature(
            (string) $order->gateway_order_id,
            $data['razorpay_payment_id'],
            $data['razorpay_signature'],
        ), 422, 'The payment signature could not be verified.');

        try {
            $payment = $this->gateway->fetchPayment($data['razorpay_payment_id']);
        } catch (Throwable $exception) {
            abort(503, 'Razorpay could not confirm payment status. The fee ledger has not been updated; retry verification shortly.');
        }

        $result = $this->settle($order, $payment, $data['razorpay_payment_id']);

        return response()->json(['data' => [
            'status' => $result['order']->status,
            'fee_record_id' => $fee,
            'message' => $result['changed'] ? 'Test payment confirmed and fee balance updated.' : 'This payment was already recorded.',
        ]]);
    }

    public function webhook(Request $request): JsonResponse
    {
        abort_unless($this->gateway->webhooksEnabled(), 503);
        $rawBody = $request->getContent();
        abort_if(strlen($rawBody) > 1_048_576, 413);
        $signature = (string) $request->header('X-Razorpay-Signature', '');
        abort_unless($this->gateway->verifyWebhookSignature($rawBody, $signature), 400, 'Invalid Razorpay webhook signature.');
        $eventId = (string) $request->header('X-Razorpay-Event-Id', '');
        abort_if($eventId === '' || strlen($eventId) > 120, 400, 'Missing or invalid Razorpay event identifier.');

        try {
            $payload = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400, 'Invalid webhook JSON.');
        }
        abort_unless(is_array($payload) && is_string($payload['event'] ?? null), 400, 'Invalid Razorpay event payload.');

        $eventType = $payload['event'];
        $bodyHash = hash('sha256', $rawBody);
        $existingEvent = RazorpayWebhookEvent::query()->where('event_id', $eventId)->first();
        if ($existingEvent !== null) {
            abort_unless(hash_equals($existingEvent->body_sha256, $bodyHash), 409, 'A different payload used this Razorpay event identifier.');

            return response()->json(['status' => 'ok', 'duplicate' => true]);
        }

        $payment = $payload['payload']['payment']['entity'] ?? null;
        if ($eventType === 'payment.captured' && is_array($payment) && is_string($payment['order_id'] ?? null)) {
            $order = RazorpayPaymentOrder::query()->where('gateway_order_id', $payment['order_id'])->first();
            if ($order !== null) {
                $this->settle($order, $payment, is_string($payment['id'] ?? null) ? $payment['id'] : '');
            }
        }

        try {
            RazorpayWebhookEvent::query()->create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'body_sha256' => $bodyHash,
                'processed_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $racedEvent = RazorpayWebhookEvent::query()->where('event_id', $eventId)->first();
            if ($racedEvent === null) {
                throw $exception;
            }
            abort_unless(hash_equals($racedEvent->body_sha256, $bodyHash), 409, 'A different payload used this Razorpay event identifier.');
        }

        return response()->json(['status' => 'ok', 'duplicate' => false]);
    }

    /** @return array{order: RazorpayPaymentOrder, changed: bool} */
    private function settle(RazorpayPaymentOrder $order, array $payment, string $paymentId): array
    {
        abort_if($paymentId === '', 422, 'Razorpay did not provide a payment identifier.');
        abort_unless(($payment['status'] ?? null) === 'captured', 409, 'The payment has not been captured; the fee ledger remains unchanged.');
        abort_unless(($payment['order_id'] ?? null) === $order->gateway_order_id, 422, 'The payment does not belong to this fee order.');
        abort_unless((int) ($payment['amount'] ?? 0) === $order->amount_paise && ($payment['currency'] ?? null) === 'INR', 422, 'The captured amount does not match the fee balance.');

        $result = DB::transaction(function () use ($order, $paymentId): array {
            $lockedOrder = RazorpayPaymentOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status === 'captured') {
                abort_unless($lockedOrder->gateway_payment_id === $paymentId, 409, 'A different payment is already attached to this fee order.');

                return ['order' => $lockedOrder, 'changed' => false, 'fee' => null];
            }
            abort_unless($lockedOrder->status === 'created', 409, 'This checkout is not open for payment confirmation.');

            $record = FeeRecord::query()->where('organization_id', $lockedOrder->organization_id)
                ->lockForUpdate()->findOrFail($lockedOrder->fee_record_id);
            $balance = $this->balancePaise($record);
            abort_if($balance !== $lockedOrder->amount_paise, 409, 'The fee balance changed after this checkout began. Contact the institution to reconcile the payment.');

            $paymentFingerprint = hash('sha256', implode('|', [
                'razorpay', $lockedOrder->organization_id, $record->id, $lockedOrder->amount_paise, $paymentId,
            ]));
            $feePayment = FeePayment::query()->create([
                'organization_id' => $lockedOrder->organization_id,
                'fee_record_id' => $record->id,
                'amount' => number_format($lockedOrder->amount_paise / 100, 2, '.', ''),
                'method' => 'razorpay',
                'reference' => $paymentId,
                'received_by' => $lockedOrder->payer_user_id,
                'received_at' => now(),
                'idempotency_key' => 'razorpay:'.$paymentId,
                'idempotency_fingerprint' => $paymentFingerprint,
            ]);
            $record->update(['status' => 'paid']);
            $lockedOrder->update([
                'fee_payment_id' => $feePayment->id,
                'gateway_payment_id' => $paymentId,
                'status' => 'captured',
                'captured_at' => now(),
            ]);

            return ['order' => $lockedOrder->refresh(), 'changed' => true, 'fee' => $record->refresh()];
        });

        if ($result['changed']) {
            $this->notifier->feePaymentRecorded($result['fee'], $order->payer_user_id, number_format($order->amount_paise / 100, 2, '.', ''));
        }

        return ['order' => $result['order'], 'changed' => $result['changed']];
    }

    private function assertPayerRole(OrganizationRole $role): void
    {
        abort_unless(in_array($role, [OrganizationRole::Student, OrganizationRole::Parent], true), 403, 'Only students and linked parents can pay a fee online.');
    }

    private function assertMayPay(FeeRecord $record, OrganizationRole $role, int $payerId): void
    {
        if ($role === OrganizationRole::Student) {
            abort_unless($record->student()->where('user_id', $payerId)->where('status', 'active')->exists(), 404);
        } else {
            abort_unless(StudentGuardian::query()->where('organization_id', $record->organization_id)
                ->where('student_id', $record->student_id)->where('guardian_user_id', $payerId)
                ->whereHas('student', fn ($students) => $students->where('status', 'active'))->exists(), 404);
        }
    }

    private function balancePaise(FeeRecord $record): int
    {
        $total = $this->toPaise((string) $record->amount);
        $paid = $this->toPaise((string) $record->payments()->sum('amount'));

        return max(0, $total - $paid);
    }

    private function toPaise(string $amount): int
    {
        if (preg_match('/\A(\d+)(?:\.(\d{1,2}))?\z/', $amount, $parts) !== 1) {
            throw new \LogicException('A stored INR amount has an unsupported decimal format.');
        }

        return ((int) $parts[1] * 100) + (int) str_pad($parts[2] ?? '', 2, '0');
    }
}
