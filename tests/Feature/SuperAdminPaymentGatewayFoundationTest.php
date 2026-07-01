<?php

namespace Tests\Feature;

use App\Models\PaymentProvider;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\TenantBillingEntry;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Payments\PaymentWebhookSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SuperAdminPaymentGatewayFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $platformAdmin;
    private TenantAccount $tenant;
    private Role $tenantAdminRole;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://' . self::CENTRAL_HOST]);

        $this->platformAdmin = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->tenantAdminRole = Role::query()->where('key', 'admin')->firstOrFail();
    }

    public function test_super_admin_can_manage_shared_payment_provider_and_create_checkout_session(): void
    {
        $index = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.payment-providers.index'));

        $index->assertOk();
        $index->assertSee('Ödeme Altyapısı');
        $index->assertSee('Tenant tarafı ileride modül olarak');
        $index->assertSee('Odeme Faz Durumu');
        $index->assertSee('Super Admin odeme omurgasi ortaktir');

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.payment-providers.store'), [
                'provider_key' => 'iyzico_shared',
                'driver_key' => 'iyzico',
                'display_name' => 'Iyzico Ortak',
                'status' => 'active',
                'checkout_mode' => 'hosted',
                'supports_shared_saas_payments' => '1',
                'supports_tenant_module' => '1',
                'notes' => 'Ortak omurga sağlayıcısı.',
                'shared_credential_is_active' => '1',
                'shared_api_key' => 'sandbox-api',
                'shared_secret_key' => 'sandbox-secret',
                'shared_merchant_key' => 'merchant-001',
                'shared_base_url' => 'https://sandbox-api.example.test',
                'shared_webhook_secret' => 'webhook-secret',
                'shared_sandbox_mode' => '1',
                'shared_credential_notes' => 'Shared credential hazır.',
            ])
            ->assertRedirect();

        $provider = PaymentProvider::query()->where('provider_key', 'iyzico_shared')->firstOrFail();

        $this->assertDatabaseHas('payment_providers', [
            'provider_key' => 'iyzico_shared',
            'status' => 'active',
            'supports_tenant_module' => 1,
        ]);
        $this->assertDatabaseHas('payment_gateway_credentials', [
            'payment_provider_id' => $provider->id,
            'scope_type' => 'super_admin_shared',
            'is_active' => 1,
        ]);

        $billingEntry = TenantBillingEntry::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'entry_type' => 'service_fee',
            'title' => 'Kurulum Bedeli',
            'direction' => 'debit',
            'amount' => 2500,
            'currency' => 'TRY',
            'entry_date' => '2026-06-27',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.payment-checkouts.store', $this->tenant), [
                'payment_provider_id' => $provider->id,
                'billing_entry_id' => $billingEntry->id,
                'amount' => 2500,
                'currency' => 'TRY',
                'title' => 'Kurulum Bedeli Tahsilatı',
                'note' => 'SaaS cari tahsilatı için ortak checkout.',
                'expires_at' => '2026-07-04',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('payment_checkout_sessions', [
            'payment_provider_id' => $provider->id,
            'tenant_account_id' => $this->tenant->id,
            'scope_type' => 'super_admin_shared',
            'payment_context' => 'saas_billing',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_provider_id' => $provider->id,
            'tenant_account_id' => $this->tenant->id,
            'transaction_type' => 'checkout_initialized',
        ]);

        $session = \App\Models\PaymentCheckoutSession::query()->where('payment_provider_id', $provider->id)->latest('id')->firstOrFail();
        $this->assertStringContainsString('/hosted-checkout/', (string) $session->checkout_url);
        $this->assertSame(route('payment-checkouts.callbacks.success', $session), data_get($session->provider_payload_json, 'success_callback_url'));
        $this->assertSame(route('payment-webhooks.receive', $provider), data_get($session->provider_payload_json, 'webhook_url'));

        $show = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.tenants.billing.index', $this->tenant));

        $show->assertOk();
        $show->assertSee('Ortak Ödeme Omurgası');
        $show->assertSee('Ödeme Linki Oluştur');
        $show->assertSee('Sonraki Teknik Faz');
    }

    public function test_iyzico_driver_can_initialize_real_api_checkout_when_live_api_mode_is_enabled(): void
    {
        Http::fake([
            'https://sandbox-api.example.test/payment/iyzipos/checkoutform/initialize/auth/ecom' => Http::response([
                'status' => 'success',
                'conversationId' => 'PAY-LIVE-001',
                'token' => 'TOKEN-123',
                'paymentPageUrl' => 'https://sandbox-checkout.example.test/pay/TOKEN-123',
            ], 200),
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.payment-providers.store'), [
                'provider_key' => 'iyzico_live_api',
                'driver_key' => 'iyzico',
                'display_name' => 'Iyzico Canli Hazirlik',
                'status' => 'active',
                'checkout_mode' => 'hosted',
                'supports_shared_saas_payments' => '1',
                'supports_tenant_module' => '1',
                'shared_credential_is_active' => '1',
                'shared_api_key' => 'sandbox-api',
                'shared_secret_key' => 'sandbox-secret',
                'shared_merchant_key' => 'merchant-001',
                'shared_base_url' => 'https://sandbox-api.example.test',
                'shared_webhook_secret' => 'webhook-secret',
                'shared_sandbox_mode' => '1',
                'shared_use_live_api' => '1',
                'shared_checkout_initialize_path' => '/payment/iyzipos/checkoutform/initialize/auth/ecom',
                'shared_timeout_seconds' => '15',
            ])
            ->assertRedirect();

        $provider = PaymentProvider::query()->where('provider_key', 'iyzico_live_api')->firstOrFail();

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.payment-checkouts.store', $this->tenant), [
                'payment_provider_id' => $provider->id,
                'amount' => 750,
                'currency' => 'TRY',
                'title' => 'Canli API Tahsilat Linki',
                'note' => 'Iyzico initialize test.',
                'expires_at' => '2026-07-04',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $session = \App\Models\PaymentCheckoutSession::query()->where('payment_provider_id', $provider->id)->latest('id')->firstOrFail();

        $this->assertSame('pending', $session->status);
        $this->assertSame('TOKEN-123', $session->gateway_reference);
        $this->assertSame('PAY-LIVE-001', $session->external_reference);
        $this->assertSame('https://sandbox-checkout.example.test/pay/TOKEN-123', $session->checkout_url);
        $this->assertSame('live_initialize_called', data_get($session->provider_payload_json, 'stage'));

        Http::assertSent(function ($request) use ($session) {
            $data = $request->data();

            return $request->url() === 'https://sandbox-api.example.test/payment/iyzipos/checkoutform/initialize/auth/ecom'
                && $request->method() === 'POST'
                && $request->hasHeader('X-IYZICO-API-KEY', 'sandbox-api')
                && $request->hasHeader('X-IYZICO-SECRET-KEY', 'sandbox-secret')
                && data_get($data, 'conversationId') === $session->reference_no
                && data_get($data, 'callbackUrl') === route('payment-checkouts.callbacks.success', $session);
        });
    }

    public function test_webhook_capture_logs_payload_without_authentication_flow(): void
    {
        $provider = PaymentProvider::query()->create([
            'provider_key' => 'iyzico_webhook',
            'driver_key' => 'iyzico',
            'display_name' => 'Iyzico Webhook',
            'status' => 'active',
            'checkout_mode' => 'hosted',
            'supports_shared_saas_payments' => true,
            'supports_tenant_module' => false,
        ]);

        $provider->sharedCredential()->create([
            'scope_type' => 'super_admin_shared',
            'is_active' => true,
            'settings_json' => ['webhook_secret' => 'secret-123'],
        ]);

        $signature = app(PaymentWebhookSignatureService::class)->sign([
            'event' => 'checkout.paid',
            'reference' => 'PAY-REF-001',
            'status' => 'success',
            'gateway_reference' => '',
            'external_reference' => '',
        ], 'secret-123');

        $response = $this->post(route('payment-webhooks.receive', $provider), [
            'event' => 'checkout.paid',
            'reference' => 'PAY-REF-001',
            'status' => 'success',
            'signature' => $signature,
        ]);

        $response->assertStatus(202);

        $this->assertDatabaseHas('payment_webhook_logs', [
            'payment_provider_id' => $provider->id,
            'event_key' => 'checkout.paid',
            'external_reference' => 'PAY-REF-001',
        ]);
    }

    public function test_paid_webhook_marks_checkout_paid_and_creates_single_saas_collection_entry(): void
    {
        $provider = PaymentProvider::query()->create([
            'provider_key' => 'iyzico_process',
            'driver_key' => 'iyzico',
            'display_name' => 'Iyzico Process',
            'status' => 'active',
            'checkout_mode' => 'hosted',
            'supports_shared_saas_payments' => true,
            'supports_tenant_module' => true,
        ]);

        $provider->sharedCredential()->create([
            'scope_type' => 'super_admin_shared',
            'is_active' => true,
            'settings_json' => ['webhook_secret' => 'secret-paid'],
        ]);

        $debit = TenantBillingEntry::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'entry_type' => 'service_fee',
            'title' => 'Kurulum Hizmeti',
            'direction' => 'debit',
            'amount' => 1800,
            'currency' => 'TRY',
            'entry_date' => '2026-06-27',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.tenants.payment-checkouts.store', $this->tenant), [
                'payment_provider_id' => $provider->id,
                'billing_entry_id' => $debit->id,
                'amount' => 1800,
                'currency' => 'TRY',
                'title' => 'Kurulum Hizmeti Tahsilatı',
                'note' => 'Webhook ile tahsil edilecek.',
                'expires_at' => '2026-07-04',
            ])->assertRedirect();

        $session = \App\Models\PaymentCheckoutSession::query()->where('payment_provider_id', $provider->id)->latest('id')->firstOrFail();

        $signature = app(PaymentWebhookSignatureService::class)->sign([
            'event' => 'checkout.paid',
            'reference' => $session->reference_no,
            'status' => 'success',
            'gateway_reference' => 'GW-001',
            'external_reference' => '',
        ], 'secret-paid');

        $this->post(route('payment-webhooks.receive', $provider), [
            'event' => 'checkout.paid',
            'reference' => $session->reference_no,
            'status' => 'success',
            'gateway_reference' => 'GW-001',
            'signature' => $signature,
        ])->assertStatus(202);

        $session->refresh();

        $this->assertSame('paid', $session->status);
        $this->assertNotNull($session->paid_at);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_checkout_session_id' => $session->id,
            'transaction_type' => 'payment_confirmed',
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('tenant_billing_entries', [
            'tenant_account_id' => $this->tenant->id,
            'entry_type' => 'collection',
            'reference_no' => $session->reference_no,
            'direction' => 'credit',
        ]);

        $firstCollectionCount = TenantBillingEntry::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('entry_type', 'collection')
            ->where('reference_no', $session->reference_no)
            ->count();

        $signatureRepeat = app(PaymentWebhookSignatureService::class)->sign([
            'event' => 'checkout.paid',
            'reference' => $session->reference_no,
            'status' => 'success',
            'gateway_reference' => 'GW-001',
            'external_reference' => '',
        ], 'secret-paid');

        $this->post(route('payment-webhooks.receive', $provider), [
            'event' => 'checkout.paid',
            'reference' => $session->reference_no,
            'status' => 'success',
            'gateway_reference' => 'GW-001',
            'signature' => $signatureRepeat,
        ])->assertStatus(202);

        $secondCollectionCount = TenantBillingEntry::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('entry_type', 'collection')
            ->where('reference_no', $session->reference_no)
            ->count();

        $this->assertSame($firstCollectionCount, $secondCollectionCount);
    }

    public function test_invalid_webhook_secret_is_rejected(): void
    {
        $provider = PaymentProvider::query()->create([
            'provider_key' => 'iyzico_reject',
            'driver_key' => 'iyzico',
            'display_name' => 'Iyzico Reject',
            'status' => 'active',
            'checkout_mode' => 'hosted',
            'supports_shared_saas_payments' => true,
            'supports_tenant_module' => false,
        ]);

        $provider->sharedCredential()->create([
            'scope_type' => 'super_admin_shared',
            'is_active' => true,
            'settings_json' => ['webhook_secret' => 'expected-secret'],
        ]);

        $this->post(route('payment-webhooks.receive', $provider), [
            'event' => 'checkout.failed',
            'reference' => 'NOPE-001',
            'status' => 'failed',
            'webhook_secret' => 'wrong-secret',
        ])->assertStatus(401);

        $this->assertDatabaseHas('payment_webhook_logs', [
            'payment_provider_id' => $provider->id,
            'status' => 'rejected',
            'external_reference' => 'NOPE-001',
        ]);
    }

    public function test_checkout_callback_pages_are_publicly_resolvable(): void
    {
        $provider = PaymentProvider::query()->create([
            'provider_key' => 'iyzico_callbacks',
            'driver_key' => 'iyzico',
            'display_name' => 'Iyzico Callbacks',
            'status' => 'active',
            'checkout_mode' => 'hosted',
            'supports_shared_saas_payments' => true,
            'supports_tenant_module' => true,
        ]);

        $session = \App\Models\PaymentCheckoutSession::query()->create([
            'payment_provider_id' => $provider->id,
            'tenant_account_id' => $this->tenant->id,
            'scope_type' => 'super_admin_shared',
            'payment_context' => 'saas_billing',
            'reference_no' => 'CALLBACK-001',
            'status' => 'pending',
            'amount' => 1000,
            'currency' => 'TRY',
        ]);

        $this->get(route('payment-checkouts.callbacks.success', $session))->assertOk()->assertSee('Ödeme Tamamlandı');
        $session->refresh();
        $this->assertDatabaseHas('payment_transactions', [
            'payment_checkout_session_id' => $session->id,
            'transaction_type' => 'customer_return_success',
        ]);

        $failedSession = \App\Models\PaymentCheckoutSession::query()->create([
            'payment_provider_id' => $provider->id,
            'tenant_account_id' => $this->tenant->id,
            'scope_type' => 'super_admin_shared',
            'payment_context' => 'saas_billing',
            'reference_no' => 'CALLBACK-FAIL',
            'status' => 'pending',
            'amount' => 900,
            'currency' => 'TRY',
        ]);

        $this->get(route('payment-checkouts.callbacks.failure', $failedSession))->assertOk()->assertSee('Ödeme Başarısız');
        $failedSession->refresh();
        $this->assertSame('failed', $failedSession->status);

        $cancelledSession = \App\Models\PaymentCheckoutSession::query()->create([
            'payment_provider_id' => $provider->id,
            'tenant_account_id' => $this->tenant->id,
            'scope_type' => 'super_admin_shared',
            'payment_context' => 'saas_billing',
            'reference_no' => 'CALLBACK-CANCEL',
            'status' => 'pending',
            'amount' => 800,
            'currency' => 'TRY',
        ]);

        $this->get(route('payment-checkouts.callbacks.cancel', $cancelledSession))->assertOk()->assertSee('Ödeme İptal Edildi');
        $cancelledSession->refresh();
        $this->assertSame('cancelled', $cancelledSession->status);
    }

    public function test_super_admin_can_list_and_filter_checkout_sessions(): void
    {
        $provider = PaymentProvider::query()->create([
            'provider_key' => 'iyzico_checkout_list',
            'driver_key' => 'iyzico',
            'display_name' => 'Iyzico Liste',
            'status' => 'active',
            'checkout_mode' => 'hosted',
            'supports_shared_saas_payments' => true,
            'supports_tenant_module' => true,
        ]);

        \App\Models\PaymentCheckoutSession::query()->create([
            'payment_provider_id' => $provider->id,
            'tenant_account_id' => $this->tenant->id,
            'scope_type' => 'super_admin_shared',
            'payment_context' => 'saas_billing',
            'reference_no' => 'PAY-LIST-001',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'TRY',
        ]);

        \App\Models\PaymentCheckoutSession::query()->create([
            'payment_provider_id' => $provider->id,
            'tenant_account_id' => $this->tenant->id,
            'scope_type' => 'super_admin_shared',
            'payment_context' => 'saas_billing',
            'reference_no' => 'PAY-LIST-002',
            'status' => 'failed',
            'amount' => 200,
            'currency' => 'TRY',
        ]);

        $response = $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.payment-checkouts.index', [
                'status' => 'failed',
                'payment_provider_id' => $provider->id,
                'q' => 'PAY-LIST-002',
            ]));

        $response->assertOk();
        $response->assertSee('Checkout Operasyon Listesi');
        $response->assertSee('Odeme Faz Durumu');
        $response->assertSee('PAY-LIST-002');
        $response->assertDontSee('PAY-LIST-001');
    }

    public function test_super_admin_can_cancel_expire_and_retry_checkout_sessions(): void
    {
        $provider = PaymentProvider::query()->create([
            'provider_key' => 'iyzico_checkout_ops',
            'driver_key' => 'iyzico',
            'display_name' => 'Iyzico Operasyon',
            'status' => 'active',
            'checkout_mode' => 'hosted',
            'supports_shared_saas_payments' => true,
            'supports_tenant_module' => true,
        ]);

        $provider->sharedCredential()->create([
            'scope_type' => 'super_admin_shared',
            'is_active' => true,
            'credentials_json' => [
                'api_key' => 'sandbox-api',
                'secret_key' => 'sandbox-secret',
            ],
            'settings_json' => [
                'base_url' => 'https://sandbox-api.example.test',
                'sandbox_mode' => true,
            ],
        ]);

        $cancelSession = \App\Models\PaymentCheckoutSession::query()->create([
            'payment_provider_id' => $provider->id,
            'payment_gateway_credential_id' => $provider->sharedCredential->id,
            'tenant_account_id' => $this->tenant->id,
            'scope_type' => 'super_admin_shared',
            'payment_context' => 'saas_billing',
            'reference_no' => 'PAY-CANCEL-001',
            'status' => 'pending',
            'amount' => 300,
            'currency' => 'TRY',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.payment-checkouts.cancel', $cancelSession))
            ->assertRedirect();

        $cancelSession->refresh();
        $this->assertSame('cancelled', $cancelSession->status);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_checkout_session_id' => $cancelSession->id,
            'transaction_type' => 'manual_cancelled',
        ]);

        $expireSession = \App\Models\PaymentCheckoutSession::query()->create([
            'payment_provider_id' => $provider->id,
            'payment_gateway_credential_id' => $provider->sharedCredential->id,
            'tenant_account_id' => $this->tenant->id,
            'scope_type' => 'super_admin_shared',
            'payment_context' => 'saas_billing',
            'reference_no' => 'PAY-EXPIRE-001',
            'status' => 'pending',
            'amount' => 350,
            'currency' => 'TRY',
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.payment-checkouts.expire', $expireSession))
            ->assertRedirect();

        $expireSession->refresh();
        $this->assertSame('expired', $expireSession->status);

        $retrySource = \App\Models\PaymentCheckoutSession::query()->create([
            'payment_provider_id' => $provider->id,
            'payment_gateway_credential_id' => $provider->sharedCredential->id,
            'tenant_account_id' => $this->tenant->id,
            'scope_type' => 'super_admin_shared',
            'payment_context' => 'saas_billing',
            'reference_no' => 'PAY-RETRY-001',
            'status' => 'failed',
            'amount' => 450,
            'currency' => 'TRY',
            'meta_json' => [
                'title' => 'Retry Tahsilatı',
                'note' => 'Önceki link başarısız oldu.',
            ],
        ]);

        $this->actingAs($this->platformAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.super.payment-checkouts.retry', $retrySource))
            ->assertRedirect()
            ->assertSessionHas('success');

        $retrySource->refresh();
        $newSessionId = (int) data_get($retrySource->meta_json, 'retried_to_checkout_session_id', 0);
        $this->assertGreaterThan(0, $newSessionId);

        $this->assertDatabaseHas('payment_checkout_sessions', [
            'id' => $newSessionId,
            'tenant_account_id' => $this->tenant->id,
            'payment_provider_id' => $provider->id,
            'status' => 'pending',
        ]);
    }

    public function test_tenant_admin_cannot_access_super_admin_payment_foundation_screens(): void
    {
        $tenantAdmin = User::query()->create([
            'name' => 'Tenant Payment Admin',
            'email' => 'tenant-payment-admin@example.test',
            'password' => 'secret-password',
            'is_platform_admin' => false,
        ]);

        UserRole::query()->create([
            'user_id' => $tenantAdmin->id,
            'tenant_account_id' => $this->tenant->id,
            'role_id' => $this->tenantAdminRole->id,
        ]);

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.payment-providers.index'))
            ->assertForbidden();

        $this->actingAs($tenantAdmin, 'web')
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.super.payment-checkouts.index'))
            ->assertForbidden();
    }
}
