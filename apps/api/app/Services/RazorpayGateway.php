<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class RazorpayGateway
{
    public function enabled(): bool
    {
        return (bool) config('services.razorpay.enabled')
            && config('services.razorpay.mode') === 'test'
            && str_starts_with((string) config('services.razorpay.key_id'), 'rzp_test_')
            && filled(config('services.razorpay.key_secret'));
    }

    public function webhooksEnabled(): bool
    {
        return $this->enabled() && filled(config('services.razorpay.webhook_secret'));
    }

    public function keyId(): string
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Razorpay test mode is not configured.');
        }

        return (string) config('services.razorpay.key_id');
    }

    /** @return array{id: string, amount: int, currency: string} */
    public function createOrder(int $amountPaise, string $receipt, array $notes): array
    {
        $response = Http::withBasicAuth($this->keyId(), (string) config('services.razorpay.key_secret'))
            ->acceptJson()->asJson()->timeout(10)
            ->post('https://api.razorpay.com/v1/orders', [
                'amount' => $amountPaise,
                'currency' => 'INR',
                'receipt' => $receipt,
                'notes' => $notes,
            ])->throw()->json();

        if (! is_array($response)
            || ! is_string($response['id'] ?? null)
            || ! is_numeric($response['amount'] ?? null)
            || ! is_string($response['currency'] ?? null)) {
            throw new RuntimeException('Razorpay returned an invalid order response.');
        }

        return ['id' => $response['id'], 'amount' => (int) $response['amount'], 'currency' => $response['currency']];
    }

    /** @return array<string, mixed> */
    public function fetchPayment(string $paymentId): array
    {
        $response = Http::withBasicAuth($this->keyId(), (string) config('services.razorpay.key_secret'))
            ->acceptJson()->timeout(10)
            ->get('https://api.razorpay.com/v1/payments/'.rawurlencode($paymentId))
            ->throw()->json();

        if (! is_array($response)) {
            throw new RuntimeException('Razorpay returned an invalid payment response.');
        }

        return $response;
    }

    public function verifyCheckoutSignature(string $trustedOrderId, string $paymentId, string $signature): bool
    {
        $secret = (string) config('services.razorpay.key_secret');
        if ($secret === '' || preg_match('/\A[0-9a-f]{64}\z/i', $signature) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $trustedOrderId.'|'.$paymentId, $secret), strtolower($signature));
    }

    public function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');
        if ($secret === '' || preg_match('/\A[0-9a-f]{64}\z/i', $signature) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature));
    }
}
