<?php

namespace App\Services;

use App\Models\TenantAccount;
use Illuminate\Support\Carbon;
use Carbon\CarbonInterface;

class TenantSubscriptionStatusService
{
    public function getStatus(TenantAccount $tenant): array
    {
        $status = $this->resolveStatus($tenant);
        $daysRemaining = $this->daysRemaining($tenant, $status);

        return [
            'status' => $status,
            'label' => $this->statusLabel($status),
            'severity' => $this->severity($status),
            'is_active' => $status === 'active',
            'is_trial' => $status === 'trial',
            'is_expired' => $status === 'expired',
            'days_remaining' => $daysRemaining,
            'message' => $this->message($status, $daysRemaining),
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
        $rawStatus = strtolower(trim((string) ($tenant->status ?? 'active')));

        if ($this->isExpiredByDate($tenant)) {
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

    private function isExpiredByDate(TenantAccount $tenant): bool
    {
        $endDate = $this->resolveEndDate($tenant);

        return $endDate?->isPast() ?? false;
    }

    private function resolveEndDate(TenantAccount $tenant): ?CarbonInterface
    {
        foreach (['trial_ends_at', 'end_date', 'expires_at'] as $attribute) {
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

        return null;
    }

    private function daysRemaining(TenantAccount $tenant, string $status): ?int
    {
        if (!in_array($status, ['trial', 'expired'], true)) {
            return null;
        }

        $endDate = $this->resolveEndDate($tenant);

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
            'active' => 'Tenant aktif ve tam erişime uygun.',
            'trial' => $daysRemaining !== null
                ? "Deneme suresi devam ediyor. Kalan gun: {$daysRemaining}."
                : 'Deneme suresi devam ediyor.',
            'expired' => 'Tenant suresi dolmus. Yalniz sinirli erisim onerilir.',
            'suspended' => 'Tenant askiya alinmis. Erisim kisitlanmali.',
            'passive' => 'Tenant pasif durumda.',
            default => 'Tenant durumu belirlenemedi.',
        };
    }
}
