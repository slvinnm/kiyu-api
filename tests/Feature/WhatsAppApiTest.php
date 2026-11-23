<?php

use App\Enums\BillingInterval;
use App\Enums\Feature;
use App\Enums\TenantStatus;
use App\Enums\WhatsAppMessageType;
use App\Models\Facility;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, facility: Facility, admin: User}
 */
function whatsappClinic(bool $grantWhatsApp = true): array
{
    $tenant = Tenant::query()->create([
        'name' => 'WA Clinic',
        'slug' => 'wa-clinic-' . uniqid(),
        'status' => TenantStatus::Active,
    ]);

    $facility = Facility::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'WA Facility',
        'code' => 'MAIN',
        'is_active' => true,
    ]);

    $admin = User::factory()->create(['role' => 'admin', 'tenant_id' => $tenant->id]);

    $plan = Plan::query()->create(['code' => 'WA-' . uniqid(), 'name' => 'WA', 'is_active' => true]);

    if ($grantWhatsApp) {
        $plan->entitlements()->create(['feature_code' => Feature::WhatsAppNotifications->value, 'value' => null]);
    }

    app(SubscriptionService::class)->start($tenant, $plan, BillingInterval::Monthly, 30);

    return compact('tenant', 'facility', 'admin');
}

beforeEach(function () {
    config([
        'services.whatsapp.enabled' => true,
        'services.whatsapp.endpoint' => 'https://wa.example.test/send',
        'services.whatsapp.method' => 'POST',
        'services.whatsapp.headers' => ['Authorization' => 'secret-token'],
        'services.whatsapp.body' => [
            'target' => '{to}',
            'message' => '{body}',
            'url' => '{media_url}',
        ],
    ]);
});

test('a text message is sent through the configured provider', function () {
    Http::fake(['wa.example.test/*' => Http::response(['status' => true, 'id' => 'msg-1'])]);

    $clinic = whatsappClinic();

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/whatsapp/messages', [
            'to' => '628123',
            'type' => 'text',
            'body' => 'Your queue number is A012',
        ])
        ->assertOk()
        ->assertJsonPath('data.id', 'msg-1');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://wa.example.test/send'
            && $request->hasHeader('Authorization', 'secret-token')
            && $request['target'] === '628123'
            && $request['message'] === 'Your queue number is A012';
    });
});

test('a media message includes the media url', function () {
    Http::fake(['wa.example.test/*' => Http::response(['status' => true])]);

    $clinic = whatsappClinic();

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/whatsapp/messages', [
            'to' => '628999',
            'type' => 'document',
            'media_url' => 'https://cdn.example.test/report.pdf',
            'filename' => 'report.pdf',
        ])
        ->assertOk();

    Http::assertSent(fn($request) => $request['url'] === 'https://cdn.example.test/report.pdf');
});

test('sending requires the whatsapp feature', function () {
    Http::fake();

    $clinic = whatsappClinic(grantWhatsApp: false);

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/whatsapp/messages', [
            'to' => '628123',
            'type' => 'text',
            'body' => 'hi',
        ])
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the gateway is provider-neutral: changing config changes the call', function () {
    Http::fake(['other.provider.test/*' => Http::response(['ok' => true])]);

    config([
        'services.whatsapp.endpoint' => 'https://other.provider.test/v1/messages',
        'services.whatsapp.body' => ['phone' => '{to}', 'text' => '{body}'],
    ]);

    $clinic = whatsappClinic();

    $this->actingAs($clinic['admin'], 'sanctum')
        ->postJson('/api/whatsapp/messages', [
            'to' => '628777',
            'type' => 'text',
            'body' => 'Hello',
        ])
        ->assertOk();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://other.provider.test/v1/messages'
            && $request['phone'] === '628777'
            && $request['text'] === 'Hello';
    });
});

test('media types are identified correctly', function () {
    expect(WhatsAppMessageType::Text->hasMedia())->toBeFalse()
        ->and(WhatsAppMessageType::Image->hasMedia())->toBeTrue()
        ->and(WhatsAppMessageType::Video->hasMedia())->toBeTrue()
        ->and(WhatsAppMessageType::Document->hasMedia())->toBeTrue();
});
