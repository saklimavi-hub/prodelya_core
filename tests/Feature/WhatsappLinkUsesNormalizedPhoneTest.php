<?php

namespace Tests\Feature;

use App\Models\TenantAccount;
use App\Models\TenantModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappLinkUsesNormalizedPhoneTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private const CENTRAL_HOST = 'prodelya_core.test';

    private TenantAccount $tenant;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = TenantAccount::query()->firstOrFail();
        $this->adminUser = User::query()->where('email', 'admin@prodelya.local')->firstOrFail();

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => null,
            ],
            ['is_enabled' => true]
        );

        TenantModule::query()->updateOrCreate(
            [
                'tenant_account_id' => $this->tenant->id,
                'module_key' => 'notification_center',
                'feature_key' => 'whatsapp_links',
            ],
            ['is_enabled' => true]
        );
    }

    public function test_whatsapp_link_uses_normalized_dial_string_and_not_raw_phone_text(): void
    {
        $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->post(route('admin.settings.notifications.whatsapp.create-link'), [
                'customer_name' => 'Ayşe',
                'recipient_phone' => '0532 123 45 67',
                'message_type' => 'general',
                'message' => 'Merhaba',
            ])
            ->assertRedirect(route('admin.settings.notifications.whatsapp'))
            ->assertSessionHas('whatsapp_result');

        $result = session('whatsapp_result');

        $this->assertIsArray($result);
        $this->assertStringStartsWith('https://wa.me/905321234567?text=', (string) $result['url']);
        $this->assertStringNotContainsString('0532 123 45 67', (string) $result['url']);
    }

    public function test_invalid_phone_does_not_create_broken_whatsapp_link(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->withServerVariables(['HTTP_HOST' => self::CENTRAL_HOST])
            ->from(route('admin.settings.notifications.whatsapp'))
            ->post(route('admin.settings.notifications.whatsapp.create-link'), [
                'customer_name' => 'Ayşe',
                'recipient_phone' => 'abc',
                'message_type' => 'general',
                'message' => 'Merhaba',
            ]);

        $response->assertRedirect(route('admin.settings.notifications.whatsapp'));
        $response->assertSessionHasErrors('recipient_phone');
        $response->assertSessionMissing('whatsapp_result');
    }
}
