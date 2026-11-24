<?php

use App\Enums\BillingInterval;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\BillingWebhookEvent;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const MIDTRANS_SERVER_KEY = 'SB-Mid-server-TESTKEY';

/**
 * @return array{tenant: Tenant, facility: Facility, admin: User, subscription: Subscription}
 */
function payingClinic(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Pay Clinic',
        'slug' => 'pay-' . uniqid(),
        'status' => TenantStatus::Active,
        'billing_email' => 'billing@pay.test',
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $tenant->id]);

    $plan = Plan::query()->create(['code' => 'PAID-' . uniqid(), 'name' => 'Paid', 'is_active' => true]);
    $plan->prices()->create([
        'billing_interval' => 'MONTHLY',
        'amount' => 150_000_00,
        'currency' => 'IDR',
        'provider' => '',
    ]);

    $subscription = app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly);

    return compact('tenant', 'facility', 'admin', 'subscription');
}

/**
 * Build a valid Midtrans webhook payload with a correct signature.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function midtransPayload(string $orderId, array $overrides = []): array
{
    $statusCode = $overrides['status_code'] ?? '200';
    $grossAmount = $overrides['gross_amount'] ?? '150000.00';

    $payload = array_merge([
        'order_id' => $orderId,
        'status_code' => $statusCode,
        'gross_amount' => $grossAmount,
        'transaction_status' => 'settlement',
        'transaction_id' => 'txn-' . uniqid(),
        'payment_type' => 'bank_transfer',
    ], $overrides);

    $payload['signature_key'] = hash(
        'sha512',
        $payload['order_id'] . $payload['status_code'] . $payload['gross_amount'] . MIDTRANS_SERVER_KEY,
    );

    return $payload;
}

beforeEach(function () {
    config(['services.midtrans.server_key' => MIDTRANS_SERVER_KEY]);
});

test('starting a charge creates a pending payment and returns a snap token', function () {
    Http::fake(['*/transactions' => Http::response([
        'token' => 'snap-token-123',
        'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/abc',
    ])]);

    $tenant = payingClinic();

    $this->actingAs($tenant['admin'], 'sanctum')
        ->postJson('/api/billing/pay')
        ->assertCreated()
        ->assertJsonPath('data.token', 'snap-token-123');

    $payment = Payment::query()->firstOrFail();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBe(150_000_00)
        ->and($payment->provider)->toBe('midtrans');

    Http::assertSent(fn($request) => str_contains($request->url(), '/transactions')
        && $request['transaction_details']['gross_amount'] === 150_000_00);
});

test('a valid webhook activates the subscription and marks the payment paid', function () {
    Http::fake(['*/transactions' => Http::response(['token' => 't', 'redirect_url' => 'r'])]);

    $tenant = payingClinic();
    $this->actingAs($tenant['admin'], 'sanctum')->postJson('/api/billing/pay')->assertCreated();

    $payment = Payment::query()->firstOrFail();
    $orderId = $payment->provider_transaction_id;

    // Force the subscription back to a non-active state to prove the webhook activates it.
    $tenant['subscription']->update(['status' => SubscriptionStatus::PastDue]);

    $this->postJson('/api/webhooks/payments/midtrans', midtransPayload($orderId))
        ->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Paid)
        ->and($tenant['subscription']->fresh()->status)->toBe(SubscriptionStatus::Active);
});

test('a webhook with a bad signature is rejected and changes nothing', function () {
    Http::fake(['*/transactions' => Http::response(['token' => 't', 'redirect_url' => 'r'])]);

    $tenant = payingClinic();
    $this->actingAs($tenant['admin'], 'sanctum')->postJson('/api/billing/pay')->assertCreated();

    $payment = Payment::query()->firstOrFail();

    $payload = midtransPayload($payment->provider_transaction_id);
    $payload['signature_key'] = 'deadbeef';

    $this->postJson('/api/webhooks/payments/midtrans', $payload)
        ->assertStatus(422);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Pending)
        ->and(BillingWebhookEvent::query()->count())->toBe(0);
});

test('a replayed webhook is idempotent', function () {
    Http::fake(['*/transactions' => Http::response(['token' => 't', 'redirect_url' => 'r'])]);

    $tenant = payingClinic();
    $this->actingAs($tenant['admin'], 'sanctum')->postJson('/api/billing/pay')->assertCreated();

    $payment = Payment::query()->firstOrFail();
    $payload = midtransPayload($payment->provider_transaction_id, ['transaction_id' => 'txn-fixed']);

    $this->postJson('/api/webhooks/payments/midtrans', $payload)->assertOk();
    $this->postJson('/api/webhooks/payments/midtrans', $payload)->assertOk();

    // One payment row, one webhook event, still paid.
    expect(Payment::query()->count())->toBe(1)
        ->and(BillingWebhookEvent::query()->count())->toBe(1)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});

test('a non-admin cannot start a charge', function () {
    $tenant = payingClinic();
    $staff = User::factory()->create(['role' => 'staff', 'tenant_id' => $tenant['tenant']->id]);

    $this->actingAs($staff, 'sanctum')
        ->postJson('/api/billing/pay')
        ->assertForbidden();
});

test('two charges on the same subscription never share an order id', function () {
    Http::fake(['*/transactions' => Http::response(['token' => 't', 'redirect_url' => 'r'])]);

    $tenant = payingClinic();

    $this->actingAs($tenant['admin'], 'sanctum')->postJson('/api/billing/pay')->assertCreated();
    $this->actingAs($tenant['admin'], 'sanctum')->postJson('/api/billing/pay')->assertCreated();

    $ids = Payment::query()->orderBy('id')->pluck('provider_transaction_id');

    expect($ids)->toHaveCount(2)
        ->and($ids->first())->not->toBe($ids->last())
        ->and($ids->first())->toEndWith('-001')
        ->and($ids->last())->toEndWith('-002');
});

test('a webhook retry that omits the transaction id is still idempotent', function () {
    Http::fake(['*/transactions' => Http::response(['token' => 't', 'redirect_url' => 'r'])]);

    $tenant = payingClinic();
    $this->actingAs($tenant['admin'], 'sanctum')->postJson('/api/billing/pay')->assertCreated();

    $payment = Payment::query()->firstOrFail();
    $payload = midtransPayload($payment->provider_transaction_id);
    unset($payload['transaction_id']);

    $this->postJson('/api/webhooks/payments/midtrans', $payload)->assertOk();
    $this->postJson('/api/webhooks/payments/midtrans', $payload)->assertOk();

    expect(BillingWebhookEvent::query()->count())->toBe(1)
        ->and($payment->fresh()->status)->toBe(PaymentStatus::Paid);
});
