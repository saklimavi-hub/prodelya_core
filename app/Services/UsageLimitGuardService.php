<?php

namespace App\Services;

use App\Models\TenantAccount;
use Illuminate\Validation\ValidationException;

class UsageLimitGuardService
{
    public function __construct(
        protected TenantUsageService $tenantUsageService
    ) {
    }

    public function canCreate(TenantAccount $tenant, string $usageKey): bool
    {
        return (bool) ($this->check($tenant, $usageKey)['allowed'] ?? true);
    }

    public function assertCanCreate(TenantAccount $tenant, string $usageKey): void
    {
        $usage = $this->check($tenant, $usageKey);

        if ($usage['allowed']) {
            return;
        }

        throw ValidationException::withMessages([
            'usage_limit' => $usage['message'],
        ]);
    }

    public function check(TenantAccount $tenant, string $usageKey): array
    {
        $usage = $this->tenantUsageService->getUsageForKey($tenant, $usageKey);
        $status = (string) ($usage['status'] ?? 'unlimited');
        $limit = $usage['limit'] ?? null;
        $current = (int) ($usage['current'] ?? 0);

        $allowed = true;

        if ($limit !== null) {
            $allowed = $current < (int) $limit;
        }

        if ($status === 'exceeded') {
            $allowed = false;
        }

        return [
            'allowed' => $allowed,
            'key' => $usage['key'] ?? $usageKey,
            'label' => $usage['label'] ?? $this->fallbackLabel($usageKey),
            'current' => $current,
            'limit' => $limit,
            'status' => $status,
            'message' => $this->messageFor($usageKey, $usage),
        ];
    }

    public function messageFor(string $usageKey, array $usage): string
    {
        return match ($usageKey) {
            'users' => 'Bu paket için kullanıcı limiti doldu.',
            'current_accounts', 'companies' => 'Yeni cari kart eklemek için paket limitiniz doldu.',
            'orders' => 'Yeni sipariş veya teklif oluşturmak için paket limitiniz doldu.',
            'products' => 'Yeni ürün eklemek için paket limitiniz doldu.',
            'supplier_feeds' => 'Yeni tedarikçi feed erişimi eklemek için paket limitiniz doldu.',
            'custom_domains' => 'Yeni özel domain eklemek için paket limitiniz doldu.',
            'api_tokens' => 'Yeni API erişimi oluşturmak için paket limitiniz doldu.',
            default => 'Bu işlem için paket limitiniz doldu.',
        };
    }

    private function fallbackLabel(string $usageKey): string
    {
        return match ($usageKey) {
            'users' => 'Kullanıcılar',
            'current_accounts' => 'Cari Kartlar',
            'companies' => 'Firmalar',
            'orders' => 'Siparişler',
            'products' => 'Ürünler',
            'supplier_feeds' => 'Tedarikçi Feed Erişimleri',
            'custom_domains' => 'Özel Domainler',
            'api_tokens' => 'API Erişimleri',
            default => 'Kullanım Limiti',
        };
    }
}
