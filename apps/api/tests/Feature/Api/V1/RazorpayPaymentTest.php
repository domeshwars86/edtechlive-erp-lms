<?php

namespace Tests\Feature\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\OrganizationRole;
use App\Enums\OrganizationType;
use App\Models\FeePayment;
use App\Models\FeeRecord;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\RazorpayPaymentOrder;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RazorpayPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_checkout_is_disabled_by_default_and_live_credentials_never_enable_it(): void
    {
        [$admin, $organization, $studentUser] = $this->workspace();
        $fee = $this->fee($organization, $admin);
        Sanctum::actingAs($studentUser);

        $base = "/api/v1/organizations/{$organization->id}";
        $this->getJson("{$base}/payments/razorpay/config")
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.key_id', null);
        $this->postJson("{$base}/fees/{$fee->id}/razorpay/checkout", [], ['Idempotency-Key' => 'razorpay-disabled-test-001'])
            ->assertStatus(503);

        $this->enableGateway(mode: 'live', keyId: 'rzp_live_example');
        $this->getJson("{$base}/payments/razorpay/config")->assertOk()->assertJsonPath('data.enabled', false);
        $this->assertDatabaseCount('razorpay_payment_orders', 0);
    }

    public function test_student_can_create_and_retry_a_server_priced_test_order(): void
    {
        [$admin, $organization, $studentUser, $student] = $this->workspace();
        $fee = $this->fee($organization, $admin, $student, '500.00');
        FeePayment::query()->create([
            'organization_id' => $organization->id, 'fee_record_id' => $fee->id,
            'amount' => '100.00', 'method' => 'cash', 'received_by' => $admin->id, 'received_at' => now(),
        ]);
        $this->enableGateway();
        Http::fake(['https://api.razorpay.com/v1/orders' => Http::response([
            'id' => 'order_test_1001', 'amount' => 40000, 'currency' => 'INR',
        ], 200)]);
        Sanctum::actingAs($studentUser);
        $url = "/api/v1/organizations/{$organization->id}/fees/{$fee->id}/razorpay/checkout";
        $headers = ['Idempotency-Key' => 'razorpay-student-order-0001'];

        $response = $this->postJson($url, ['amount' => 1, 'currency' => 'USD'], $headers)
            ->assertCreated()->assertJsonPath('data.order_id', 'order_test_1001')
            ->assertJsonPath('data.amount', 40000)->assertJsonPath('data.currency', 'INR')
            ->assertJsonPath('data.key_id', 'rzp_test_public_key')->assertJsonPath('data.status', 'created');
        $this->postJson($url, [], $headers)
            ->assertOk()->assertJsonPath('data.order_id', 'order_test_1001');

        $this->assertDatabaseHas('razorpay_payment_orders', [
            'organization_id' => $organization->id,
            'fee_record_id' => $fee->id,
            'payer_user_id' => $studentUser->id,
            'gateway_order_id' => 'order_test_1001',
            'amount_paise' => 40000,
            'currency' => 'INR',
            'status' => 'created',
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.razorpay.com/v1/orders'
            && $request['amount'] === 40000 && $request['currency'] === 'INR'
            && strlen($request['receipt']) <= 40 && $request['notes']['fee_record_id'] === (string) $fee->id);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/organizations/{$organization->id}/fees/{$fee->id}/payments", [
            'amount' => '400.00', 'method' => 'cash',
        ], ['Idempotency-Key' => 'razorpay-manual-receipt-001'])->assertStatus(409);
    }

    public function test_only_the_student_or_linked_parent_can_start_a_checkout(): void
    {
        [$admin, $organization, $studentUser, , $parentUser, $otherStudent] = $this->workspace(withOtherStudent: true);
        $fee = $this->fee($organization, $admin, $otherStudent);
        $this->enableGateway();
        Sanctum::actingAs($studentUser);

        $this->postJson("/api/v1/organizations/{$organization->id}/fees/{$fee->id}/razorpay/checkout", [], [
            'Idempotency-Key' => 'razorpay-unauthorized-checkout-01',
        ])->assertNotFound();

        Sanctum::actingAs($parentUser);
        $this->postJson("/api/v1/organizations/{$organization->id}/fees/{$fee->id}/razorpay/checkout", [], [
            'Idempotency-Key' => 'razorpay-unauthorized-checkout-02',
        ])->assertNotFound();
        $this->assertDatabaseCount('razorpay_payment_orders', 0);
    }

    public function test_checkout_signature_and_server_payment_status_are_required_before_receipting(): void
    {
        [$admin, $organization, $studentUser] = $this->workspace();
        $fee = $this->fee($organization, $admin);
        $this->enableGateway();
        Http::fake([
            'https://api.razorpay.com/v1/orders' => Http::response(['id' => 'order_test_2001', 'amount' => 12500, 'currency' => 'INR'], 200),
            'https://api.razorpay.com/v1/payments/pay_test_2001' => Http::response([
                'id' => 'pay_test_2001', 'order_id' => 'order_test_2001', 'amount' => 12500, 'currency' => 'INR', 'status' => 'captured',
            ], 200),
        ]);
        Sanctum::actingAs($studentUser);
        $base = "/api/v1/organizations/{$organization->id}/fees/{$fee->id}";
        $this->postJson("{$base}/razorpay/checkout", [], ['Idempotency-Key' => 'razorpay-confirm-order-0001'])->assertCreated();
        $signature = hash_hmac('sha256', 'order_test_2001|pay_test_2001', 'test_key_secret');

        $this->postJson("{$base}/razorpay/verify", [
            'razorpay_order_id' => 'order_test_2001',
            'razorpay_payment_id' => 'pay_test_2001',
            'razorpay_signature' => str_repeat('0', 64),
        ])->assertStatus(422);
        $this->assertDatabaseCount('fee_payments', 0);

        $this->postJson("{$base}/razorpay/verify", [
            'razorpay_order_id' => 'order_test_2001',
            'razorpay_payment_id' => 'pay_test_2001',
            'razorpay_signature' => $signature,
        ])->assertOk()->assertJsonPath('data.status', 'captured');
        $this->postJson("{$base}/razorpay/verify", [
            'razorpay_order_id' => 'order_test_2001',
            'razorpay_payment_id' => 'pay_test_2001',
            'razorpay_signature' => $signature,
        ])->assertOk()->assertJsonPath('data.message', 'This payment was already recorded.');

        $this->assertDatabaseCount('fee_payments', 1);
        $this->assertDatabaseHas('fee_payments', [
            'organization_id' => $organization->id,
            'fee_record_id' => $fee->id,
            'amount' => '125.00',
            'method' => 'razorpay',
            'reference' => 'pay_test_2001',
        ]);
        $this->assertDatabaseHas('fee_records', ['id' => $fee->id, 'status' => 'paid']);
        $this->assertDatabaseHas('razorpay_payment_orders', ['gateway_payment_id' => 'pay_test_2001', 'status' => 'captured']);
        $this->postJson("{$base}/razorpay/checkout", [], ['Idempotency-Key' => 'razorpay-confirm-order-0001'])
            ->assertOk()->assertJsonPath('data.status', 'captured');
    }

    public function test_failed_provider_order_creation_can_retry_with_the_same_idempotency_key(): void
    {
        [$admin, $organization, $studentUser] = $this->workspace();
        $fee = $this->fee($organization, $admin);
        $this->enableGateway();
        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;

            return $requests === 1
                ? Http::response(['error' => ['description' => 'temporary provider failure']], 500)
                : Http::response(['id' => 'order_test_retry', 'amount' => 12500, 'currency' => 'INR'], 200);
        });
        Sanctum::actingAs($studentUser);
        $url = "/api/v1/organizations/{$organization->id}/fees/{$fee->id}/razorpay/checkout";
        $headers = ['Idempotency-Key' => 'razorpay-retry-order-000001'];

        $this->postJson($url, [], $headers)->assertStatus(502);
        $failedReceipt = RazorpayPaymentOrder::query()->value('receipt');
        $this->assertDatabaseHas('razorpay_payment_orders', ['status' => 'failed']);
        $this->postJson($url, [], $headers)->assertCreated()->assertJsonPath('data.order_id', 'order_test_retry');
        $this->assertDatabaseHas('razorpay_payment_orders', ['status' => 'created', 'gateway_order_id' => 'order_test_retry']);
        $this->assertNotSame($failedReceipt, RazorpayPaymentOrder::query()->value('receipt'));
        Http::assertSentCount(2);
    }

    public function test_signed_captured_webhook_is_deduplicated_without_storing_payload(): void
    {
        [$admin, $organization, $studentUser, $student] = $this->workspace();
        $fee = $this->fee($organization, $admin, $student);
        $this->enableGateway(webhookSecret: 'test_webhook_secret');
        Http::fake(['https://api.razorpay.com/v1/orders' => Http::response([
            'id' => 'order_test_3001', 'amount' => 12500, 'currency' => 'INR',
        ], 200)]);
        Sanctum::actingAs($studentUser);
        $this->postJson("/api/v1/organizations/{$organization->id}/fees/{$fee->id}/razorpay/checkout", [], [
            'Idempotency-Key' => 'razorpay-webhook-order-0001',
        ])->assertCreated();
        auth()->forgetGuards();

        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => [
            'id' => 'pay_test_3001', 'order_id' => 'order_test_3001', 'amount' => 12500, 'currency' => 'INR', 'status' => 'captured',
        ]]]], JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, 'test_webhook_secret');
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_test_3001',
        ];

        $this->call('POST', '/api/v1/integrations/razorpay/webhook', [], [], [], $headers, $body)
            ->assertOk()->assertJsonPath('duplicate', false);
        $this->call('POST', '/api/v1/integrations/razorpay/webhook', [], [], [], $headers, $body)
            ->assertOk()->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('fee_payments', 1);
        $this->assertDatabaseCount('razorpay_webhook_events', 1);
        $this->assertDatabaseHas('razorpay_webhook_events', ['event_id' => 'evt_test_3001', 'event_type' => 'payment.captured']);
        $this->assertDatabaseHas('fee_records', ['id' => $fee->id, 'status' => 'paid']);
    }

    public function test_webhook_rejects_invalid_signatures_and_event_id_payload_collisions(): void
    {
        $this->enableGateway(webhookSecret: 'test_webhook_secret');
        $rawBody = '{"event":"payment.failed","payload":{}}';
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => str_repeat('0', 64),
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_test_collision',
        ];
        $this->call('POST', '/api/v1/integrations/razorpay/webhook', [], [], [], $headers, $rawBody)->assertBadRequest();
        $this->assertDatabaseCount('razorpay_webhook_events', 0);

        $headers['HTTP_X_RAZORPAY_SIGNATURE'] = hash_hmac('sha256', $rawBody, 'test_webhook_secret');
        $this->call('POST', '/api/v1/integrations/razorpay/webhook', [], [], [], $headers, $rawBody)->assertOk();
        $differentBody = '{"event":"order.paid","payload":{}}';
        $headers['HTTP_X_RAZORPAY_SIGNATURE'] = hash_hmac('sha256', $differentBody, 'test_webhook_secret');
        $this->call('POST', '/api/v1/integrations/razorpay/webhook', [], [], [], $headers, $differentBody)->assertStatus(409);
        $this->assertDatabaseCount('razorpay_webhook_events', 1);
    }

    private function enableGateway(string $mode = 'test', string $keyId = 'rzp_test_public_key', string $webhookSecret = ''): void
    {
        Config::set('services.razorpay.mode', $mode);
        Config::set('services.razorpay.key_id', $keyId);
        Config::set('services.razorpay.key_secret', 'test_key_secret');
        Config::set('services.razorpay.webhook_secret', $webhookSecret);
        Config::set('services.razorpay.enabled', $mode === 'test' && str_starts_with($keyId, 'rzp_test_'));
    }

    private function workspace(bool $withOtherStudent = false): array
    {
        $admin = User::factory()->create();
        $studentUser = User::factory()->create(['email' => 'learner@example.test']);
        $parentUser = User::factory()->create(['email' => 'parent@example.test']);
        $organization = Organization::query()->create([
            'name' => 'Northstar Academy', 'slug' => 'northstar-'.str()->random(6),
            'type' => OrganizationType::School, 'status' => 'active', 'timezone' => 'Asia/Kolkata',
        ]);
        foreach ([[$admin, OrganizationRole::Admin], [$studentUser, OrganizationRole::Student], [$parentUser, OrganizationRole::Parent]] as [$user, $role]) {
            OrganizationMembership::query()->create([
                'organization_id' => $organization->id, 'user_id' => $user->id,
                'role' => $role, 'status' => MembershipStatus::Active, 'accepted_at' => now(),
            ]);
        }
        $student = Student::query()->create([
            'organization_id' => $organization->id, 'user_id' => $studentUser->id,
            'name' => 'Learner One', 'admission_number' => 'S-100', 'status' => 'active',
        ]);
        StudentGuardian::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'guardian_user_id' => $parentUser->id, 'linked_by' => $admin->id,
            'relationship' => 'parent', 'is_primary' => true, 'linked_at' => now(),
        ]);
        $otherStudent = null;
        if ($withOtherStudent) {
            $otherStudent = Student::query()->create([
                'organization_id' => $organization->id, 'name' => 'Other Learner',
                'admission_number' => 'S-200', 'status' => 'active',
            ]);
        }

        return [$admin, $organization, $studentUser, $student, $parentUser, $otherStudent];
    }

    private function fee(Organization $organization, User $admin, ?Student $student = null, string $amount = '125.00'): FeeRecord
    {
        $student ??= Student::query()->where('organization_id', $organization->id)->firstOrFail();

        return FeeRecord::query()->create([
            'organization_id' => $organization->id, 'student_id' => $student->id,
            'title' => 'Term fee', 'amount' => $amount, 'status' => 'unpaid', 'created_by' => $admin->id,
        ]);
    }
}
