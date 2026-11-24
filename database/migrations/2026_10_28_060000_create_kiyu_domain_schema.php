<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('PENDING');
            $table->string('billing_email')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->boolean('allow_public_registration')->default(false);
            $table->foreignId('owner_user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('display_in_kiosk')->default(true);
            $table->timestamps();

            $table->unique(['facility_id', 'code']);
        });

        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('type'); // INTAKE, SERVICE, CASHIER, COUNTER
            $table->string('queue_prefix', 10)->default('A');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['department_id', 'code']);
        });

        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('workflows')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->string('name');
            $table->integer('sequence');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_optional')->default(false);
            $table->boolean('can_skip')->default(false);
            $table->timestamps();

            $table->unique(['workflow_id', 'sequence']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('role');
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'role']);
            $table->unique(['id', 'tenant_id']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreign('owner_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('tenant_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->json('facility_ids')->nullable();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'email', 'role']);
            $table->index(['tenant_id', 'email']);
        });

        Schema::create('user_facility_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'facility_id']);
            $table->index(['tenant_id', 'facility_id']);
        });

        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->string('name');
            $table->string('credential_hash', 64)->unique();
            $table->string('pairing_code_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
        });

        Schema::create('display_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('name');
            $table->string('credential_hash', 64)->unique();
            $table->string('pairing_code_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->timestamp('paired_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id']);
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->softDeletes();
        });

        Schema::create('queue_session_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->date('counter_date');
            $table->integer('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['facility_id', 'counter_date']);
        });

        Schema::create('queue_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->date('counter_date');
            $table->integer('last_sequence')->default(0);
            $table->timestamps();

            $table->unique(['station_id', 'counter_date']);
        });

        Schema::create('queue_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('workflow_id')->constrained('workflows')->restrictOnDelete();
            $table->string('session_number');
            $table->string('tracking_token')->unique();
            $table->tinyInteger('priority')->default(1); // 1 NORMAL, 2 PRIORITY, 3 EMERGENCY
            $table->string('intake_channel'); // ONLINE, KIOSK, MANUAL
            $table->string('status')->default('WAITING'); // AWAITING_CHECKIN, WAITING, IN_PROGRESS, COMPLETED, CANCELLED
            $table->string('online_active_key')->nullable()->index();
            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['facility_id', 'session_number']);
            $table->index(['tenant_id', 'facility_id', 'created_at']);
            $table->index(['department_id', 'status']);
            $table->softDeletes();
        });

        Schema::create('queue_acquisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_session_id')->constrained('queue_sessions')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('channel'); // ONLINE, KIOSK, MANUAL
            $table->string('status')->default('ACQUIRED'); // ACQUIRED, REGISTERED, CANCELLED
            $table->string('idempotency_key')->nullable();
            $table->foreignId('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['department_id', 'status']);
            $table->unique(['tenant_id', 'idempotency_key']);
        });

        Schema::create('queue_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_session_id')->constrained('queue_sessions')->cascadeOnDelete();
            $table->foreignId('workflow_step_id')->constrained('workflow_steps')->cascadeOnDelete();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->string('queue_number');
            $table->tinyInteger('priority')->default(1);
            $table->integer('internal_sequence');
            $table->string('status')->default('CREATED');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transferred_from_ticket_id')->nullable()->constrained('queue_tickets')->nullOnDelete();
            $table->foreignId('transferred_to_station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'status', 'priority', 'internal_sequence'], 'idx_station_queue_selection');
            $table->index(['queue_session_id', 'status']);
        });

        Schema::create('queue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_ticket_id')->constrained('queue_tickets')->cascadeOnDelete();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['queue_ticket_id', 'created_at']);
        });

        Schema::create('service_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('source_queue_session_id')->constrained('queue_sessions')->cascadeOnDelete();
            $table->foreignId('target_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('target_queue_session_id')->nullable()->constrained('queue_sessions')->nullOnDelete();
            $table->foreignId('referred_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('PENDING');
            $table->tinyInteger('priority')->default(1);
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('platform_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id']);
            $table->index('action');
            $table->index(['entity_type']);
            $table->index(['entity_id']);
            $table->index(['created_at']);
        });

        /*
        |--------------------------------------------------------------------------
        | Billing
        |--------------------------------------------------------------------------
        */

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('billing_interval'); // MONTHLY, YEARLY
            $table->integer('amount'); // minor currency units
            $table->string('currency', 3);
            $table->string('provider')->default('');
            $table->string('provider_price_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['plan_id', 'billing_interval', 'currency', 'provider']);
        });

        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->string('feature_code');
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'feature_code']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete();
            $table->string('status'); // TRIALING, ACTIVE, PAST_DUE, CANCELLED, EXPIRED
            $table->string('billing_interval');
            $table->string('provider')->default('');
            $table->string('provider_customer_id')->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->string('provider');
            $table->string('provider_transaction_id')->nullable();
            $table->integer('amount');
            $table->string('currency', 3);
            $table->string('status'); // PENDING, PAID, FAILED, REFUNDED
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_transaction_id']);
        });

        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('event_id');
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->string('status'); // RECEIVED, PROCESSED, FAILED
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('service_transfers');
        Schema::dropIfExists('queue_events');
        Schema::dropIfExists('queue_tickets');
        Schema::dropIfExists('queue_acquisitions');
        Schema::dropIfExists('queue_sessions');
        Schema::dropIfExists('queue_counters');
        Schema::dropIfExists('queue_session_counters');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('display_devices');
        Schema::dropIfExists('kiosk_devices');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflows');
        Schema::dropIfExists('user_facility_assignments');
        Schema::dropIfExists('tenant_invitations');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('users');
        Schema::dropIfExists('stations');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('facilities');
    }
};
