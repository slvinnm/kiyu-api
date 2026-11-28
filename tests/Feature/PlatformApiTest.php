<?php

use App\Enums\BillingInterval;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\BillingWebhookEvent;
use App\Models\Facility;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function platformAdmin(): User
{
    return User::factory()->create([
        'role' => 'platform_admin',
        'tenant_id' => null,
    ]);
}

function seedPlanWithPrice(string $code = 'starter'): Plan
{
    $plan = Plan::query()->create([
        'code' => $code,
        'name' => ucfirst($code),
        'is_active' => true,
    ]);

    PlanPrice::query()->create([
        'plan_id' => $plan->id,
        'billing_interval' => BillingInterval::Monthly,
        'amount' => 150_000,
        'currency' => 'IDR',
        'provider' => '',
        'is_active' => true,
    ]);

    return $plan;
}

function seedTenantWithSubscription(Plan $plan): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Acme Org',
        'slug' => 'acme-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $subscription = Subscription::query()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'plan_price_id' => $plan->prices()->first()?->id,
        'status' => SubscriptionStatus::Active,
        'billing_interval' => BillingInterval::Monthly,
        'provider' => 'midtrans',
    ]);

    return [$tenant, $subscription];
}

test('platform dashboard requires a platform account', function () {
    $this->getJson('/api/portal/dashboard')->assertUnauthorized();
});

test('platform dashboard reports real tenant and subscription counts', function () {
    $plan = seedPlanWithPrice();
    [$tenant, $subscription] = seedTenantWithSubscription($plan);
    Facility::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main', 'code' => 'MAIN']);

    Payment::query()->create([
        'subscription_id' => $subscription->id,
        'provider' => 'midtrans',
        'provider_transaction_id' => 'tx-1',
        'amount' => 150_000,
        'currency' => 'IDR',
        'status' => PaymentStatus::Paid,
        'paid_at' => now(),
    ]);

    $this->actingAs(platformAdmin(), 'sanctum')
        ->getJson('/api/portal/dashboard')
        ->assertOk()
        ->assertJsonPath('data.tenants.total', 1)
        ->assertJsonPath('data.collection_in_range', 150_000)
        ->assertJsonPath('data.mrr.amount', 150_000)
        ->assertJsonPath('data.mrr.subscriptions', 1);
});

test('platform tenant list, detail, suspend and reactivate are audited', function () {
    $plan = seedPlanWithPrice();
    [$tenant] = seedTenantWithSubscription($plan);
    $admin = platformAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/portal/tenants')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/portal/tenants/{$tenant->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $tenant->id);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/portal/tenants/{$tenant->id}/suspend", ['reason' => 'Payment fraud'])
        ->assertOk()
        ->assertJsonPath('data.status', 'SUSPENDED');

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'platform.tenant.suspended',
        'entity_id' => (string) $tenant->id,
        'platform_user_id' => $admin->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/portal/tenants/{$tenant->id}/reactivate")
        ->assertOk()
        ->assertJsonPath('data.status', 'ACTIVE');
});

test('platform subscription and payment listings work', function () {
    $plan = seedPlanWithPrice();
    [$tenant, $subscription] = seedTenantWithSubscription($plan);

    $payment = Payment::query()->create([
        'subscription_id' => $subscription->id,
        'provider' => 'midtrans',
        'provider_transaction_id' => 'tx-9',
        'amount' => 150_000,
        'currency' => 'IDR',
        'status' => PaymentStatus::Paid,
        'paid_at' => now(),
    ]);

    $admin = platformAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/portal/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/portal/subscriptions/{$subscription->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $subscription->id);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/portal/payments')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/portal/payments/{$payment->id}")
        ->assertOk()
        ->assertJsonPath('data.amount', 150_000);
});

test('platform webhook history shows processing status', function () {
    BillingWebhookEvent::query()->create([
        'provider' => 'midtrans',
        'event_id' => 'evt-1',
        'event_type' => 'payment.succeeded',
        'status' => 'FAILED',
        'received_at' => now(),
    ]);

    $this->actingAs(platformAdmin(), 'sanctum')
        ->getJson('/api/portal/payments-webhooks')
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.status', 'FAILED');
});

test('platform can create a plan, add a price and set entitlements', function () {
    $admin = platformAdmin();

    $planId = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/portal/plans', [
            'code' => 'growth',
            'name' => 'Growth',
            'prices' => [
                ['billing_interval' => 'MONTHLY', 'amount' => 250_000, 'currency' => 'IDR'],
            ],
            'entitlements' => [
                ['feature_code' => 'max_facilities', 'limit' => 5],
                ['feature_code' => 'report_queue_overview'],
            ],
        ])
        ->assertCreated()
        ->json('data.id');

    // A new price for the same interval deactivates the old row (history kept).
    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/portal/plans/{$planId}/prices", [
            'billing_interval' => 'MONTHLY',
            'amount' => 300_000,
            'currency' => 'IDR',
        ])
        ->assertCreated();

    expect(PlanPrice::query()->where('plan_id', $planId)->where('is_active', true)->count())->toBe(1);
    expect(PlanPrice::query()->where('plan_id', $planId)->count())->toBe(2);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/portal/plans/{$planId}/entitlements", [
            'entitlements' => [['feature_code' => 'max_kiosk_devices', 'limit' => 3]],
        ])
        ->assertOk()
        ->assertJsonPath('data.entitlements.0.feature_code', 'max_kiosk_devices');
});

test('platform SaaS reports aggregate real data', function () {
    $plan = seedPlanWithPrice();
    [$tenant, $subscription] = seedTenantWithSubscription($plan);

    Payment::query()->create([
        'subscription_id' => $subscription->id,
        'provider' => 'midtrans',
        'provider_transaction_id' => 'tx-r',
        'amount' => 150_000,
        'currency' => 'IDR',
        'status' => PaymentStatus::Paid,
        'paid_at' => now(),
    ]);

    $admin = platformAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/portal/reports/revenue')
        ->assertOk()
        ->assertJsonPath('data.total', 150_000);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/portal/reports/growth')
        ->assertOk()
        ->assertJsonPath('data.total_tenants', 1);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/portal/reports/subscriptions')
        ->assertOk()
        ->assertJsonPath('data.by_plan.0.code', 'starter');
});

test('support access grant routes are removed', function () {
    $plan = seedPlanWithPrice();
    [$tenant] = seedTenantWithSubscription($plan);
    $admin = platformAdmin();

    // Impersonation is out of scope; the grant endpoints are gone (404) while
    // historical support records remain in the database.
    $this->asContext('portal')->actingAs($admin, 'sanctum')
        ->getJson('/api/portal/support-access')
        ->assertNotFound();

    $this->asContext('portal')->actingAs($admin, 'sanctum')
        ->postJson('/api/portal/support-access', [
            'tenant_id' => $tenant->id,
            'reason' => 'Investigating failed payment',
            'hours' => 2,
        ])
        ->assertNotFound();
});

test('platform settings persist registration policy and reserved subdomains', function () {
    $admin = platformAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/portal/settings')
        ->assertOk()
        ->assertJsonPath('data.allow_public_signup', true);

    $this->actingAs($admin, 'sanctum')
        ->putJson('/api/portal/settings', [
            'allow_public_signup' => false,
            'reserved_subdomains' => ['kiyu', 'app', 'api', 'custom'],
        ])
        ->assertOk()
        ->assertJsonPath('data.allow_public_signup', false)
        ->assertJsonPath('data.reserved_subdomains.3', 'custom');
});

test('a tenant admin cannot reach platform routes', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Acme',
        'slug' => 'acme-x',
        'status' => TenantStatus::Active,
    ]);

    $owner = User::factory()->create(['role' => 'tenant_admin', 'tenant_id' => $tenant->id]);

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/portal/dashboard')
        ->assertForbidden();
});

test('the tenant admin portal serves the organization dashboard', function () {
    $plan = seedPlanWithPrice();
    [$tenant] = seedTenantWithSubscription($plan);
    Facility::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main', 'code' => 'MAIN']);

    $owner = User::factory()->create([
        'email' => 'owner@acme.test',
        'role' => 'tenant_admin',
        'tenant_id' => $tenant->id,
    ]);

    $this->actingAs($owner, 'sanctum')
        ->getJson('/api/app/dashboard')
        ->assertOk()
        ->assertJsonPath('data.tenant.id', $tenant->id)
        ->assertJsonPath('data.counts.facilities', 1)
        ->assertJsonPath('data.subscription.status', 'ACTIVE');
});
