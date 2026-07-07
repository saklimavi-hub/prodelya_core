<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsOrderShowFixtures;
use Tests\TestCase;

class OrderDeliveryLabelPrintAccessTest extends TestCase
{
    use BuildsOrderShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOrderShowFixtures();
    }

    public function test_label_print_view_respects_tenant_scope_and_hides_sensitive_fields(): void
    {
        $order = $this->createConvertedOrderForShow();
        $host = $this->tenantHostForOrder($order);

        $this->actingAs($this->orderShowAdminUser)
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get(route('admin.orders.delivery-labels.print', $order))
            ->assertOk()
            ->assertSee('Teslimat Etiketleri')
            ->assertSee($order->document_number)
            ->assertDontSee('file_path')
            ->assertDontSee('physical_path')
            ->assertDontSee('supplier_cost')
            ->assertDontSee('current_account_id');

        $otherTenant = TenantAccount::query()->create([
            'name' => 'Etiket Dışı Tenant',
            'legal_name' => 'Etiket Dışı Tenant Ltd. Şti.',
            'slug' => 'etiket-disi-tenant',
            'panel_subdomain' => 'etiket-disi-tenant',
            'status' => 'active',
            'package_key' => 'enterprise',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ]);

        $otherUser = User::query()->create([
            'name' => 'Etiket Yabancı Kullanıcı',
            'email' => 'foreign-label-user@prodelya.local',
            'password' => bcrypt('password'),
        ]);

        UserRole::query()->create([
            'tenant_account_id' => $otherTenant->id,
            'user_id' => $otherUser->id,
            'role_id' => Role::query()->where('key', 'tenant_owner')->firstOrFail()->id,
        ]);

        $this->actingAs($otherUser)
            ->withServerVariables(['HTTP_HOST' => $host])
            ->get(route('admin.orders.delivery-labels.print', $order))
            ->assertForbidden();
    }

    private function tenantHostForOrder(Order $order): string
    {
        $tenant = TenantAccount::query()->findOrFail($order->tenant_account_id);

        return $tenant->panel_subdomain . '.prodelya_core.test';
    }
}
