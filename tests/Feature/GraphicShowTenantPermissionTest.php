<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\BuildsGraphicShowFixtures;
use Tests\TestCase;

class GraphicShowTenantPermissionTest extends TestCase
{
    use BuildsGraphicShowFixtures;
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGraphicShowFixtures();
    }

    public function test_graphic_show_allows_same_tenant_and_blocks_foreign_tenant_record(): void
    {
        $workForm = $this->createGraphicShowWorkForm('GRAPHIC-TENANT-001');

        $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm))
            ->assertOk();

        $foreignTenant = TenantAccount::query()->create([
            'name' => 'Graphic Foreign Tenant',
            'legal_name' => 'Graphic Foreign Tenant Ltd.',
            'slug' => 'graphic-foreign-tenant',
            'panel_subdomain' => 'graphic-foreign-tenant',
            'status' => 'active',
            'default_locale' => 'tr',
            'default_currency' => 'TL',
            'timezone' => 'Europe/Istanbul',
        ]);

        $workForm->forceFill(['tenant_account_id' => $foreignTenant->id])->save();

        $this->actingAs($this->graphicAdminUser)
            ->withServerVariables(['HTTP_HOST' => self::GRAPHIC_SHOW_HOST])
            ->get(route('admin.graphics.show', $workForm->fresh()))
            ->assertForbidden();
    }
}
