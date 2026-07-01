<?php

namespace App\Services;

use App\Models\TenantAccount;
use App\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Carbon\CarbonInterface;

class TenantSubscriptionStatusService
{
    private const LIFECYCLE_STATE_SETTING = 'subscription_lifecycle_state';
    private const TRIAL_START_SETTING = 'subscription_trial_starts_at';
    private const TRIAL_END_SETTING = 'subscription_trial_ends_at';
    private const PACKAGE_START_SETTING = 'subscription_package_starts_at';
    private const PACKAGE_END_SETTING = 'subscription_package_ends_at';
    private const STATUS_NOTE_SETTING = 'subscription_status_note';
    private const SUSPENDED_REASON_SETTING = 'subscription_suspended_reason';
    private const STATUS_UPDATED_AT_SETTING = 'subscription_status_updated_at';

    public function getStatus(TenantAccount $tenant): array
    {
        $status = $this->resolveStatus($tenant);
        $daysRemaining = $this->daysRemaining($tenant, $status);
        $trialEndsAt = $this->resolveTrialEndDate($tenant);
        $packageEndsAt = $this->resolvePackageEndDate($tenant);
        $statusNote = $this->stringSetting($tenant, self::STATUS_NOTE_SETTING);
        $suspendedReason = $this->stringSetting($tenant, self::SUSPENDED_REASON_SETTING);
        $statusUpdatedAt = $this->resolveDateFromSetting($tenant, self::STATUS_UPDATED_AT_SETTING);

        return [
            'status' => $status,
            'label' => $this->statusLabel($status),
            'severity' => $this->severity($status),
            'is_active' => $status === 'active',
            'is_trial' => $status === 'trial',
            'is_expired' => $status === 'expired',
            'days_remaining' => $daysRemaining,
            'message' => $this->message($status, $daysRemaining),
            'raw_status' => $this->resolveLifecycleState($tenant),
            'trial_starts_at' => $this->resolveTrialStartDate($tenant),
            'trial_ends_at' => $trialEndsAt,
            'package_starts_at' => $this->resolvePackageStartDate($tenant),
            'package_ends_at' => $packageEndsAt,
            'status_note' => $statusNote,
            'suspended_reason' => $suspendedReason,
            'status_updated_at' => $statusUpdatedAt,
            'warning_label' => $this->warningLabel($status, $daysRemaining),
        ];
    }

    public function isActive(TenantAccount $tenant): bool
    {
        return $this->resolveStatus($tenant) === 'active';
    }

    public function isTrial(TenantAccount $tenant): bool
    {
        return $this->resolveStatus($tenant) === 'trial';
    }

    public function isExpired(TenantAccount $tenant): bool
    {
        return $this->resolveStatus($tenant) === 'expired';
    }

    public function isSuspended(TenantAccount $tenant): bool
    {
        return $this->resolveStatus($tenant) === 'suspended';
    }

    public function isPassive(TenantAccount $tenant): bool
    {
        return $this->resolveStatus($tenant) === 'passive';
    }

    public function canAccessAdmin(TenantAccount $tenant): bool
    {
        return in_array($this->resolveStatus($tenant), ['active', 'trial', 'expired'], true);
    }

    public function canCreateOrUpdate(TenantAccount $tenant): bool
    {
        return in_array($this->resolveStatus($tenant), ['active', 'trial'], true);
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Aktif',
            'trial' => 'Deneme',
            'expired' => 'Suresi Dolmus',
            'suspended' => 'Askiya Alinmis',
            'passive' => 'Pasif',
            default => 'Bilinmiyor',
        };
    }

    private function resolveStatus(TenantAccount $tenant): string
    {
        $rawStatus = $this->resolveLifecycleState($tenant);

        if ($rawStatus === 'trial' && $this->resolveTrialEndDate($tenant)?->isPast()) {
            return 'expired';
        }

        if (in_array($rawStatus, ['active', 'expired'], true) && $this->resolvePackageEndDate($tenant)?->isPast()) {
            return 'expired';
        }

        return match ($rawStatus) {
            'active' => 'active',
            'trial' => 'trial',
            'suspended' => 'suspended',
            'passive', 'inactive' => 'passive',
            'expired' => 'expired',
            default => 'passive',
        };
    }

    private function resolveLifecycleState(TenantAccount $tenant): string
    {
        $settingState = strtolower(trim($this->stringSetting($tenant, self::LIFECYCLE_STATE_SETTING) ?? ''));

        if ($settingState !== '') {
            return match ($settingState) {
                'active' => 'active',
                'trial' => 'trial',
                'expired' => 'expired',
                'suspended' => 'suspended',
                'passive', 'inactive' => 'passive',
                default => strtolower(trim((string) ($tenant->status ?? 'active'))),
            };
        }

        return strtolower(trim((string) ($tenant->status ?? 'active')));
    }

    private function resolveTrialStartDate(TenantAccount $tenant): ?CarbonInterface
    {
        return $this->resolveDateValue($tenant, ['trial_starts_at'], [self::TRIAL_START_SETTING]);
    }

    private function resolveTrialEndDate(TenantAccount $tenant): ?CarbonInterface
    {
        return $this->resolveDateValue($tenant, ['trial_ends_at'], [self::TRIAL_END_SETTING, 'trial_ends_at']);
    }

    private function resolvePackageStartDate(TenantAccount $tenant): ?CarbonInterface
    {
        return $this->resolveDateValue($tenant, ['start_date', 'starts_at'], [self::PACKAGE_START_SETTING]);
    }

    private function resolvePackageEndDate(TenantAccount $tenant): ?CarbonInterface
    {
        return $this->resolveDateValue($tenant, ['end_date', 'expires_at'], [self::PACKAGE_END_SETTING, 'subscription_ends_at']);
    }

    private function daysRemaining(TenantAccount $tenant, string $status): ?int
    {
        if (!in_array($status, ['trial', 'expired', 'active'], true)) {
            return null;
        }

        $endDate = $status === 'trial'
            ? $this->resolveTrialEndDate($tenant)
            : $this->resolvePackageEndDate($tenant);

        if (!$endDate) {
            return null;
        }

        return now()->startOfDay()->diffInDays($endDate->copy()->startOfDay(), false);
    }

    private function severity(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'trial' => 'info',
            'expired' => 'warning',
            'suspended' => 'danger',
            'passive' => 'muted',
            default => 'muted',
        };
    }

    private function message(string $status, ?int $daysRemaining): string
    {
        return match ($status) {
            'active' => $daysRemaining !== null
                ? "Tenant aktif. Paket kalan gün: {$daysRemaining}."
                : 'Tenant aktif ve tam erişime uygun.',
            'trial' => $daysRemaining !== null
                ? "Deneme suresi devam ediyor. Kalan gun: {$daysRemaining}."
                : 'Deneme suresi devam ediyor.',
            'expired' => 'Tenant suresi dolmus. Yalniz sinirli erisim onerilir.',
            'suspended' => 'Tenant askiya alinmis. Erisim kisitlanmali.',
            'passive' => 'Tenant pasif durumda.',
            default => 'Tenant durumu belirlenemedi.',
        };
    }

    private function warningLabel(string $status, ?int $daysRemaining): ?string
    {
        if ($status === 'expired') {
            return 'Süresi dolmuş';
        }

        if ($daysRemaining === null) {
            return null;
        }

        if ($daysRemaining === 0) {
            return 'Bugün bitiyor';
        }

        if ($daysRemaining > 0 && $daysRemaining <= 7) {
            return '7 gün içinde bitecek';
        }

        return null;
    }

    private function resolveDateValue(TenantAccount $tenant, array $attributes, array $settingKeys): ?CarbonInterface
    {
        foreach ($attributes as $attribute) {
            $value = $tenant->getAttribute($attribute);

            if (blank($value)) {
                continue;
            }

            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        foreach ($settingKeys as $settingKey) {
            $value = $this->stringSetting($tenant, $settingKey);

            if (blank($value)) {
                continue;
            }

            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function resolveDateFromSetting(TenantAccount $tenant, string $settingKey): ?CarbonInterface
    {
        $value = $this->stringSetting($tenant, $settingKey);

        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringSetting(TenantAccount $tenant, string $key): ?string
    {
        if (!$tenant->exists) {
            return null;
        }

        $value = TenantSetting::getValue($tenant->id, $key);

        if (blank($value)) {
            return null;
        }

        return trim((string) $value);
    }
}
