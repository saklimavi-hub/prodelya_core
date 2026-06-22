<?php

namespace App\Services;

use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Notifications\NotificationTemplateDefaultSeederService;
use Illuminate\Support\Facades\DB;

class TenantOnboardingDefaultsService
{
    public function __construct(
        private readonly TenantPrintSettingSyncService $tenantPrintSettingSyncService,
        private readonly NotificationTemplateDefaultSeederService $notificationTemplateDefaultSeederService,
        private readonly TenantAccessService $tenantAccessService,
        private readonly WorkFolderPathService $workFolderPathService,
        private readonly TenantCompanyProfileService $tenantCompanyProfileService,
    ) {
    }

    public function prepareDefaults(TenantAccount $tenant, User $platformAdmin): TenantOnboardingResult
    {
        $result = new TenantOnboardingResult($tenant->id);

        DB::transaction(function () use ($tenant, $platformAdmin, $result): void {
            $printReport = $this->tenantPrintSettingSyncService->syncForTenant($tenant);
            $result->addSection(
                'print_settings',
                'Baskı ayarları',
                (int) ($printReport['created'] ?? 0),
                (int) ($printReport['skipped_existing'] ?? 0)
            );

            $notificationReport = $this->notificationTemplateDefaultSeederService->syncTenantDefaultTemplates($tenant);
            $result->addSection(
                'notification_templates',
                'Bildirim şablonları',
                (int) ($notificationReport['created_count'] ?? 0),
                (int) ($notificationReport['skipped_count'] ?? 0)
            );

            [$settingsPrepared, $settingsSkipped] = $this->prepareTenantSettings($tenant);
            $result->addSection('tenant_settings', 'Tenant varsayılanları', $settingsPrepared, $settingsSkipped);

            [$portalPrepared, $portalSkipped] = $this->preparePortalDefaults($tenant);
            $result->addSection('portal_defaults', 'Portal varsayılanları', $portalPrepared, $portalSkipped);

            [$workFolderPrepared, $workFolderSkipped] = $this->prepareWorkFolderDefaults($tenant);
            $result->addSection('work_folder', 'Çalışma klasörü kökü', $workFolderPrepared, $workFolderSkipped);

            $result->addSection(
                'tenant_bootstrap',
                'İlk firma/cari durumu',
                0,
                $tenant->companies()->exists() || $tenant->currentAccounts()->exists() ? 1 : 0
            );
        });

        return $result;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function prepareTenantSettings(TenantAccount $tenant): array
    {
        $defaults = [
            'default_locale' => [
                'value' => $tenant->default_locale ?: 'tr',
                'type' => 'string',
                'description' => 'Tenant varsayılan dil kodu',
            ],
            'default_currency' => [
                'value' => $tenant->default_currency ?: 'TL',
                'type' => 'string',
                'description' => 'Tenant varsayılan para birimi',
            ],
            'timezone' => [
                'value' => $tenant->timezone ?: 'Europe/Istanbul',
                'type' => 'string',
                'description' => 'Tenant varsayılan zaman dilimi',
            ],
            'number_format_locale' => [
                'value' => $tenant->number_format_locale ?: 'tr_TR',
                'type' => 'string',
                'description' => 'Tenant sayı ve para formatı locale değeri',
            ],
            'storage_disk' => [
                'value' => 'local',
                'type' => 'string',
                'description' => 'Tenant dosya depolama diski',
            ],
            'smtp_enabled' => [
                'value' => false,
                'type' => 'boolean',
                'description' => 'Tenant SMTP hazırlık bayrağı',
            ],
            'smtp_is_active' => [
                'value' => false,
                'type' => 'boolean',
                'description' => 'Tenant SMTP aktiflik durumu',
            ],
            'whatsapp_is_active' => [
                'value' => false,
                'type' => 'boolean',
                'description' => 'Tenant WhatsApp hazır mesaj aktiflik durumu',
            ],
            'notification_email_enabled' => [
                'value' => true,
                'type' => 'boolean',
                'description' => 'E-posta bildirim kanalı varsayılanı',
            ],
            'notification_whatsapp_enabled' => [
                'value' => true,
                'type' => 'boolean',
                'description' => 'WhatsApp bildirim kanalı varsayılanı',
            ],
            'notification_sms_enabled' => [
                'value' => false,
                'type' => 'boolean',
                'description' => 'SMS bildirim kanalı varsayılanı',
            ],
            'customer_notification_enabled' => [
                'value' => true,
                'type' => 'boolean',
                'description' => 'Müşteri bildirimleri varsayılanı',
            ],
            'internal_notification_enabled' => [
                'value' => true,
                'type' => 'boolean',
                'description' => 'İç bildirimler varsayılanı',
            ],
            'quote_customer_approval_enabled' => [
                'value' => $this->tenantAccessService->canAccessModule($tenant, 'quote_customer_approval'),
                'type' => 'boolean',
                'description' => 'Teklif müşteri onayı modül varsayılanı',
            ],
            'graphic_customer_approval_enabled' => [
                'value' => $this->tenantAccessService->canAccessModule($tenant, 'graphic_customer_approval'),
                'type' => 'boolean',
                'description' => 'Grafik müşteri onayı modül varsayılanı',
            ],
        ];

        $defaults = array_merge($defaults, $this->tenantCompanyProfileService->defaultsForTenant($tenant));

        $prepared = 0;
        $skipped = 0;

        foreach ($defaults as $key => $meta) {
            if ($this->settingExists($tenant, $key)) {
                $skipped++;
                continue;
            }

            $tenant->settings()->create([
                'key' => $key,
                'value' => $this->normalizeSettingValue($meta['value'], $meta['type']),
                'type' => $meta['type'],
                'description' => $meta['description'],
                'is_public' => false,
            ]);

            $prepared++;
        }

        return [$prepared, $skipped];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function preparePortalDefaults(TenantAccount $tenant): array
    {
        $moduleEnabled = $this->tenantAccessService->canAccessModule($tenant, 'customer_portal');

        $defaults = [
            'portal_enabled' => [
                'value' => $moduleEnabled,
                'type' => 'boolean',
                'description' => 'Müşteri portalı tenant seviyesi varsayılanı',
            ],
            'enable_customer_portal' => [
                'value' => $moduleEnabled,
                'type' => 'boolean',
                'description' => 'Legacy müşteri portalı erişim köprüsü',
            ],
        ];

        $prepared = 0;
        $skipped = 0;

        foreach ($defaults as $key => $meta) {
            if ($this->settingExists($tenant, $key)) {
                $skipped++;
                continue;
            }

            $tenant->settings()->create([
                'key' => $key,
                'value' => $this->normalizeSettingValue($meta['value'], $meta['type']),
                'type' => $meta['type'],
                'description' => $meta['description'],
                'is_public' => false,
            ]);

            $prepared++;
        }

        return [$prepared, $skipped];
    }

    /**
     * @return array{0:int,1:int}
     */
    private function prepareWorkFolderDefaults(TenantAccount $tenant): array
    {
        if ($this->settingExists($tenant, 'work_folder_root_name')) {
            return [0, 1];
        }

        $baseName = $tenant->slug ?: $tenant->panel_subdomain ?: $tenant->name ?: WorkFolderPathService::BASE_FOLDER;
        $rootName = $this->workFolderPathService->normalizeSegment($baseName, 32, WorkFolderPathService::BASE_FOLDER);

        $tenant->settings()->create([
            'key' => 'work_folder_root_name',
            'value' => $rootName,
            'type' => 'string',
            'description' => 'Sipariş ve iş formu çalışma klasörleri için güvenli kök adı',
            'is_public' => false,
        ]);

        return [1, 0];
    }

    private function settingExists(TenantAccount $tenant, string $key): bool
    {
        return TenantSetting::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('key', $key)
            ->exists();
    }

    private function normalizeSettingValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) ((int) $value),
            'array', 'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            default => (string) ($value ?? ''),
        };
    }
}
