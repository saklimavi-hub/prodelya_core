<?php

namespace App\Services;

use App\Models\Role;
use App\Models\StandardPrintType;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Models\UserRole;
use App\Services\Notifications\NotificationTemplateDefaultSeederService;

class TenantOnboardingStatusService
{
    public function __construct(
        private readonly TenantAccessService $tenantAccessService,
        private readonly NotificationTemplateDefaultSeederService $notificationTemplateDefaultSeederService,
    ) {
    }

    /**
     * @return array<string, bool|int>
     */
    public function forTenant(TenantAccount $tenant): array
    {
        $requiredPrintSettings = StandardPrintType::query()
            ->where('status', StandardPrintType::STATUS_ACTIVE)
            ->count();

        $existingPrintSettings = $tenant->printSettings()
            ->distinct('standard_print_type_id')
            ->count('standard_print_type_id');

        $requiredTemplateSlots = count($this->notificationTemplateDefaultSeederService->activeDefaultTemplateSlots());
        $existingTemplateSlots = $tenant->notificationTemplates()->count();

        return [
            'has_package' => filled($tenant->package_key),
            'has_owner' => $this->hasOwner($tenant),
            'has_active_owner' => $this->hasActiveOwner($tenant),
            'has_active_user' => $this->hasActiveUser($tenant),
            'has_finance_user' => $this->hasFinanceUser($tenant),
            'has_operations_user' => $this->hasOperationsUser($tenant),
            'has_panel_domain' => filled($tenant->panel_subdomain),
            'has_company_profile' => $this->hasCompanyProfile($tenant),
            'has_print_settings' => $requiredPrintSettings > 0 && $existingPrintSettings >= $requiredPrintSettings,
            'has_notification_templates' => $requiredTemplateSlots > 0 && $existingTemplateSlots >= $requiredTemplateSlots,
            'has_tenant_settings_defaults' => $this->hasTenantSettingsDefaults($tenant),
            'has_portal_defaults' => $this->hasPortalDefaults($tenant),
            'has_work_folder_root' => filled(TenantSetting::getValue($tenant->id, 'work_folder_root_name')),
            'has_smtp_config' => filled(TenantSetting::getValue($tenant->id, 'smtp_host'))
                || filled(TenantSetting::getValue($tenant->id, 'smtp_from_email')),
            'has_whatsapp_config' => filled(TenantSetting::getValue($tenant->id, 'whatsapp_sender_label'))
                || (bool) TenantSetting::getValue($tenant->id, 'whatsapp_is_active', false),
            'has_first_company_current_account' => $tenant->companies()->exists() && $tenant->currentAccounts()->exists(),
            'print_settings_count' => $existingPrintSettings,
            'notification_templates_count' => $existingTemplateSlots,
        ];
    }

    private function hasCompanyProfile(TenantAccount $tenant): bool
    {
        $displayName = TenantSetting::getValue($tenant->id, 'company_display_name');
        $phone = TenantSetting::getValue($tenant->id, 'company_phone');
        $email = TenantSetting::getValue($tenant->id, 'company_email');

        return filled($displayName ?: $tenant->name)
            && (filled($phone) || filled($email));
    }

    private function hasOwner(TenantAccount $tenant): bool
    {
        $tenantOwnerRoleId = Role::query()
            ->where('key', 'tenant_owner')
            ->where('is_active', true)
            ->value('id');

        if (!$tenantOwnerRoleId) {
            return false;
        }

        return UserRole::query()
            ->where('tenant_account_id', $tenant->id)
            ->where('role_id', $tenantOwnerRoleId)
            ->exists();
    }

    private function hasActiveOwner(TenantAccount $tenant): bool
    {
        return $this->hasOwner($tenant);
    }

    private function hasActiveUser(TenantAccount $tenant): bool
    {
        return UserRole::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('role', fn ($query) => $query->where('is_active', true))
            ->whereHas('user', fn ($query) => $query->where('is_platform_admin', false))
            ->exists();
    }

    private function hasFinanceUser(TenantAccount $tenant): bool
    {
        return UserRole::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('user', fn ($query) => $query->where('is_platform_admin', false))
            ->whereHas('role', function ($query): void {
                $query->where('is_active', true)
                    ->whereIn('key', ['tenant_owner', 'admin', 'finance']);
            })
            ->exists();
    }

    private function hasOperationsUser(TenantAccount $tenant): bool
    {
        return UserRole::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('user', fn ($query) => $query->where('is_platform_admin', false))
            ->whereHas('role', function ($query): void {
                $query->where('is_active', true)
                    ->whereIn('key', ['tenant_owner', 'admin', 'sales', 'graphic', 'supplier_operator', 'production', 'warehouse', 'delivery']);
            })
            ->exists();
    }

    private function hasTenantSettingsDefaults(TenantAccount $tenant): bool
    {
        $requiredKeys = [
            'default_locale',
            'default_currency',
            'timezone',
            'number_format_locale',
            'storage_disk',
            'smtp_enabled',
            'smtp_is_active',
            'whatsapp_is_active',
            'notification_email_enabled',
            'notification_whatsapp_enabled',
            'notification_sms_enabled',
            'customer_notification_enabled',
            'internal_notification_enabled',
            'quote_customer_approval_enabled',
            'graphic_customer_approval_enabled',
        ];

        $existing = $tenant->settings()
            ->whereIn('key', $requiredKeys)
            ->pluck('key')
            ->all();

        return count(array_unique($existing)) === count($requiredKeys);
    }

    private function hasPortalDefaults(TenantAccount $tenant): bool
    {
        $portalEnabled = TenantSetting::query()
            ->where('tenant_account_id', $tenant->id)
            ->whereIn('key', ['portal_enabled', 'enable_customer_portal'])
            ->pluck('key')
            ->all();

        if (count(array_unique($portalEnabled)) !== 2) {
            return false;
        }

        $moduleEnabled = $this->tenantAccessService->canAccessModule($tenant, 'customer_portal');

        return (bool) TenantSetting::getValue($tenant->id, 'portal_enabled', false) === $moduleEnabled
            && (bool) TenantSetting::getValue($tenant->id, 'enable_customer_portal', false) === $moduleEnabled;
    }
}
