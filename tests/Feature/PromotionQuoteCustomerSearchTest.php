<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyRole;
use App\Models\TenantAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionQuoteCustomerSearchTest extends TestCase
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

    public function test_customer_search_requires_minimum_three_characters(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson(route('admin.promotion-quotes.customer-search', ['q' => 'ab']));

        $response->assertOk()
            ->assertJsonPath('meta.minimum_length', 3)
            ->assertJsonPath('meta.message', 'Müşteri aramak için en az 3 karakter yazın.')
            ->assertJsonCount(0, 'data');
    }

    public function test_customer_search_is_case_insensitive_and_scoped_to_tenant_data(): void
    {
        $customer = $this->createCustomer($this->tenant->id, [
            'legal_name' => 'Hizli Arama Test A.Ş.',
            'email' => 'teklif@hizli.test',
            'phone' => '05320001122',
            'tax_number' => '1234567890',
        ]);

        CompanyContact::query()->create([
            'tenant_account_id' => $this->tenant->id,
            'company_id' => $customer->id,
            'name' => 'Ayşe Teklif',
            'email' => 'ayse@hizli.test',
            'phone' => '02125557788',
            'mobile' => '05321114455',
            'is_primary' => true,
        ]);

        $otherTenant = TenantAccount::query()->create([
            'name' => 'İkinci Tenant',
            'legal_name' => 'İkinci Tenant Ltd.',
            'slug' => 'ikinci-tenant',
            'panel_subdomain' => 'ikinci-tenant',
            'status' => 'active',
            'package_key' => 'demo',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $this->createCustomer($otherTenant->id, [
            'legal_name' => 'Hizli Arama Gizli Ltd.',
            'tax_number' => '9999999999',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson(route('admin.promotion-quotes.customer-search', ['q' => 'HIZ']));

        $response->assertOk();
        $response->assertJsonFragment(['legal_name' => 'Hizli Arama Test A.Ş.']);
        $response->assertJsonMissing(['legal_name' => 'Hizli Arama Gizli Ltd.']);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson(route('admin.promotion-quotes.customer-search', ['q' => '05320001122']))
            ->assertOk()
            ->assertJsonFragment(['legal_name' => 'Hizli Arama Test A.Ş.']);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson(route('admin.promotion-quotes.customer-search', ['q' => 'teklif@hizli.test']))
            ->assertOk()
            ->assertJsonFragment(['legal_name' => 'Hizli Arama Test A.Ş.']);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->getJson(route('admin.promotion-quotes.customer-search', ['q' => '1234567890']))
            ->assertOk()
            ->assertJsonFragment(['legal_name' => 'Hizli Arama Test A.Ş.']);
    }

    private function createCustomer(int $tenantId, array $attributes = []): Company
    {
        $company = Company::query()->create(array_merge([
            'tenant_account_id' => $tenantId,
            'legal_name' => 'Arama Müşterisi',
            'status' => 'active',
            'portal_enabled' => false,
        ], $attributes));

        CompanyRole::query()->create([
            'tenant_account_id' => $tenantId,
            'company_id' => $company->id,
            'role_key' => 'customer',
        ]);

        return $company;
    }
}
