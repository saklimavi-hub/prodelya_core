<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickCustomerWhatsappPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private User $adminUser;
    private TenantAccount $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();
        $this->tenant = TenantAccount::query()->orderBy('id')->firstOrFail();
    }

    public function test_quote_quick_customer_modal_shows_whatsapp_phone_label_and_prefix(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.promotion-quotes.create'));

        $response->assertOk()
            ->assertSee('WhatsApp Cep Telefonu')
            ->assertSee('🇹🇷 +90')
            ->assertSee('5xx xxx xx xx');
    }

    public function test_quick_customer_store_keeps_phone_data_in_existing_fields_without_breaking_selection_flow(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->postJson(route('admin.promotion-quotes.quick-customer.store'), [
                'legal_name' => 'Quick Whatsapp Customer Ltd.',
                'tax_number' => '7777777777',
                'identity_type' => 'company',
                'email' => 'quick-whatsapp@example.test',
                'phone' => '0532 456 78 90',
                'contact_name' => 'Modal Yetkili',
                'city' => 'İstanbul',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Müşteri kaydedildi ve teklif formuna seçildi.');

        $company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'Quick Whatsapp Customer Ltd.')
            ->firstOrFail();

        $this->assertSame('0532 456 78 90', $company->phone);
        $this->assertSame('0532 456 78 90', $company->mobile);
    }
}
