<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantDeliveryType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantDeliveryTypeSettingsTest extends TestCase
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
        $this->tenant = $this->adminUser->preferredTenant() ?? TenantAccount::query()->firstOrFail();
    }

    public function test_tenant_can_list_create_update_and_set_default_delivery_types(): void
    {
        $list = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->get(route('admin.settings.delivery-types.index'));

        $list->assertOk()
            ->assertSee('Teslimat Tipleri')
            ->assertSee('Ofis Teslim')
            ->assertSee('Kargo Karşı Ödemeli')
            ->assertSee('Müşteri Teslim Alacak');

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.delivery-types.store'), [
                'name' => 'Kurumsal Sevkiyat',
                'description' => 'Kurumsal teslimat akışı',
                'sort_order' => 5,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.settings.delivery-types.index'));

        $created = TenantDeliveryType::query()
            ->forTenant($this->tenant->id)
            ->where('name', 'Kurumsal Sevkiyat')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.settings.delivery-types.update', $created), [
                'name' => 'Kurumsal Sevkiyat Güncel',
                'code' => 'kurumsal-sevkiyat-guncel',
                'description' => 'Güncellendi',
                'sort_order' => 15,
                'is_active' => 0,
                'is_default' => 0,
            ])
            ->assertRedirect(route('admin.settings.delivery-types.index'));

        $created->refresh();
        $this->assertSame('Kurumsal Sevkiyat Güncel', $created->name);
        $this->assertFalse($created->is_active);

        $office = TenantDeliveryType::query()
            ->forTenant($this->tenant->id)
            ->where('name', 'Ofis Teslim')
            ->firstOrFail();

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.delivery-types.default', $office))
            ->assertRedirect(route('admin.settings.delivery-types.index'));

        $this->assertSame($office->id, TenantDeliveryType::query()->forTenant($this->tenant->id)->where('is_default', true)->value('id'));
    }

    public function test_tenant_cannot_update_other_tenant_delivery_type(): void
    {
        $otherTenant = TenantAccount::query()->create([
            'name' => 'Other Delivery Type Tenant',
            'legal_name' => 'Other Delivery Type Tenant Ltd.',
            'slug' => 'other-delivery-type-tenant',
            'panel_subdomain' => 'other-delivery-type-tenant',
            'status' => 'active',
        ]);

        $foreignType = TenantDeliveryType::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'name' => 'Foreign Type',
            'code' => 'foreign-type',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->put(route('admin.settings.delivery-types.update', $foreignType), [
                'name' => 'Hack',
                'code' => 'hack',
                'is_active' => 1,
                'is_default' => 1,
                'sort_order' => 1,
            ])
            ->assertForbidden();
    }
}
