<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRole;
use App\Models\CurrentAccountLink;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteQuickCustomerCreateTest extends TestCase
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

    public function test_quick_customer_create_creates_customer_role_and_current_account_link(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->postJson(route('admin.promotion-quotes.quick-customer.store'), [
                'legal_name' => 'Modalden Hızlı Müşteri Ltd.',
                'tax_number' => '5555555555',
                'identity_type' => 'company',
                'email' => 'hizli@modal.test',
                'phone' => '05324567890',
                'contact_name' => 'Deniz Yetkili',
                'city' => 'İstanbul',
                'address_note' => 'Hızlı kayıt notu',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Müşteri kaydedildi ve teklif formuna seçildi.')
            ->assertJsonPath('data.legal_name', 'Modalden Hızlı Müşteri Ltd.');

        $company = Company::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('legal_name', 'Modalden Hızlı Müşteri Ltd.')
            ->firstOrFail();

        $this->assertDatabaseHas('company_roles', [
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => 'customer',
        ]);

        $this->assertDatabaseHas('company_contacts', [
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'Deniz Yetkili',
        ]);

        $link = CurrentAccountLink::query()
            ->where('tenant_account_id', $this->tenant->id)
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->where('link_id', $company->id)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame($link?->current_account_id, $response->json('data.current_account_id'));
    }

    public function test_quick_customer_create_returns_validation_errors_for_invalid_tax_number_and_duplicate_match(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->postJson(route('admin.promotion-quotes.quick-customer.store'), [
                'legal_name' => 'Eksik VKN Müşteri',
                'tax_number' => '123',
                'identity_type' => 'company',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_number');

        $company = Company::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'legal_name' => 'Aynı Müşteri A.Ş.',
            'tax_number' => '1111111111',
            'status' => 'active',
            'portal_enabled' => false,
        ]);

        CompanyRole::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $company->id,
            'role_key' => 'customer',
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->postJson(route('admin.promotion-quotes.quick-customer.store'), [
                'legal_name' => 'Aynı Müşteri A.Ş.',
                'tax_number' => '1111111111',
                'identity_type' => 'company',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('legal_name');
    }
}
