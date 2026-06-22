<?php

namespace App\Services\Notifications;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItemPrintGraphic;
use App\Models\OrderItemProcurement;
use App\Models\OrderItemWorkForm;
use App\Models\OrderItemWorkFormDelivery;
use App\Models\OrderPayment;
use App\Models\SupplierProcurementRequest;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Collection;

class NotificationRecipientResolver
{
    public function __construct(
        protected NotificationEventCatalogService $eventCatalogService
    ) {
    }

    public function resolve(string $eventKey, string $audienceType, mixed $source): array
    {
        $normalizedEventKey = $this->eventCatalogService->normalizeEventKey($eventKey);
        $tenant = $this->resolveTenant($source);

        if (!$tenant) {
            return [];
        }

        $recipients = match ($audienceType) {
            'customer' => $this->resolveCustomerRecipients($source),
            'tenant_admin', 'admin' => $this->resolveTenantAdmins($tenant),
            'finance_team', 'finance' => $this->resolveRoleRecipients($tenant, 'finance'),
            'graphic_team' => $this->resolveRoleRecipients($tenant, 'graphic'),
            'production_team' => $this->resolveRoleRecipients($tenant, 'production'),
            'procurement_team' => $this->resolveRoleRecipients($tenant, 'supplier_operator'),
            'delivery_team' => $this->resolveRoleRecipients($tenant, 'delivery'),
            'sales_owner' => $this->resolveSalesOwnerRecipients($tenant, $source),
            'internal' => $this->resolveInternalRecipients($tenant, $normalizedEventKey),
            default => $this->fallbackRecipients($tenant),
        };

        return $this->uniqueRecipients($recipients);
    }

    public function resolveCustomerRecipients(mixed $source): array
    {
        $company = $this->resolveCustomerCompany($source);

        if (!$company) {
            return [];
        }

        $primaryContact = $company->getPrimaryContact();

        return [[
            'type' => 'customer',
            'name' => $primaryContact?->name ?: $company->legal_name ?: $company->name,
            'email' => $primaryContact?->email ?: $company->email,
            'phone' => $primaryContact?->phone ?: $company->mobile ?: $company->phone,
            'user_id' => null,
            'company_id' => $company->id,
            'audience_type' => 'customer',
        ]];
    }

    public function resolveTenantAdmins(TenantAccount $tenant): array
    {
        return $this->resolveUsersForRole($tenant, ['tenant_owner', 'admin'], 'admin');
    }

    public function resolveRoleRecipients(TenantAccount $tenant, string $roleOrPermission): array
    {
        $roleMap = [
            'finance' => ['finance'],
            'graphic' => ['graphic'],
            'production' => ['production'],
            'supplier_operator' => ['supplier_operator'],
            'delivery' => ['delivery'],
            'sales' => ['sales'],
        ];

        $roles = $roleMap[$roleOrPermission] ?? [$roleOrPermission];
        $recipients = $this->resolveUsersForRole($tenant, $roles, $this->audienceForRole($roleOrPermission));

        return !empty($recipients)
            ? $recipients
            : $this->fallbackRecipients($tenant);
    }

    public function fallbackRecipients(TenantAccount $tenant): array
    {
        return $this->resolveTenantAdmins($tenant);
    }

    private function resolveInternalRecipients(TenantAccount $tenant, string $eventKey): array
    {
        $event = $this->eventCatalogService->getEvent($eventKey);
        $category = $event['category'] ?? 'quote';

        return match ($category) {
            'graphic' => $this->resolveRoleRecipients($tenant, 'graphic'),
            'procurement' => $this->resolveRoleRecipients($tenant, 'supplier_operator'),
            'production' => $this->resolveRoleRecipients($tenant, 'production'),
            'delivery' => $this->resolveRoleRecipients($tenant, 'delivery'),
            'finance' => $this->resolveRoleRecipients($tenant, 'finance'),
            default => $this->fallbackRecipients($tenant),
        };
    }

    private function resolveSalesOwnerRecipients(TenantAccount $tenant, mixed $source): array
    {
        $owner = $this->resolveSourceCreator($source);

        if ($owner && $this->userBelongsToTenant($owner, $tenant->id)) {
            return [[
                'type' => 'user',
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => null,
                'user_id' => $owner->id,
                'company_id' => null,
                'audience_type' => 'sales_owner',
            ]];
        }

        $salesRecipients = $this->resolveRoleRecipients($tenant, 'sales');

        return !empty($salesRecipients)
            ? $salesRecipients
            : $this->fallbackRecipients($tenant);
    }

    private function resolveUsersForRole(TenantAccount $tenant, array $roleKeys, string $audienceType): array
    {
        $userRoles = UserRole::query()
            ->with(['user:id,name,email', 'role:id,key'])
            ->where('tenant_account_id', $tenant->id)
            ->whereHas('role', fn ($query) => $query->whereIn('key', $roleKeys))
            ->get();

        return $userRoles
            ->map(function (UserRole $userRole) use ($audienceType): ?array {
                $user = $userRole->user;

                if (!$user) {
                    return null;
                }

                return [
                    'type' => 'user',
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => null,
                    'user_id' => $user->id,
                    'company_id' => null,
                    'audience_type' => $audienceType,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function uniqueRecipients(array $recipients): array
    {
        return collect($recipients)
            ->filter(function (?array $recipient): bool {
                if (!is_array($recipient)) {
                    return false;
                }

                return filled($recipient['email'] ?? null)
                    || filled($recipient['phone'] ?? null)
                    || filled($recipient['user_id'] ?? null);
            })
            ->unique(fn (array $recipient) => implode('|', [
                $recipient['user_id'] ?? '',
                $recipient['email'] ?? '',
                $recipient['phone'] ?? '',
                $recipient['audience_type'] ?? '',
            ]))
            ->values()
            ->all();
    }

    private function resolveTenant(mixed $source): ?TenantAccount
    {
        if ($source instanceof TenantAccount) {
            return $source;
        }

        if (is_object($source) && isset($source->tenant_account_id)) {
            return TenantAccount::query()->find($source->tenant_account_id);
        }

        return null;
    }

    private function resolveCustomerCompany(mixed $source): ?Company
    {
        if ($source instanceof Company) {
            return $source;
        }

        if ($source instanceof Order) {
            return $source->customer;
        }

        if ($source instanceof OrderItemWorkForm) {
            return $source->order?->customer;
        }

        if ($source instanceof OrderItemPrintGraphic) {
            return $source->order?->customer;
        }

        if ($source instanceof OrderItemProcurement) {
            return $source->order?->customer;
        }

        if ($source instanceof SupplierProcurementRequest) {
            return $source->items->first()?->procurement?->order?->customer;
        }

        if ($source instanceof OrderItemWorkFormDelivery) {
            return $source->workForm?->order?->customer;
        }

        if ($source instanceof OrderPayment) {
            return $source->order?->customer;
        }

        return null;
    }

    private function resolveSourceCreator(mixed $source): ?User
    {
        $creatorId = null;

        if (is_object($source) && isset($source->created_by)) {
            $creatorId = $source->created_by;
        }

        if (!$creatorId) {
            return null;
        }

        return User::query()->find($creatorId);
    }

    private function userBelongsToTenant(User $user, int $tenantId): bool
    {
        return UserRole::query()
            ->where('user_id', $user->id)
            ->where('tenant_account_id', $tenantId)
            ->exists();
    }

    private function audienceForRole(string $roleOrPermission): string
    {
        return match ($roleOrPermission) {
            'finance' => 'finance',
            'graphic' => 'graphic_team',
            'production' => 'production_team',
            'supplier_operator' => 'procurement_team',
            'delivery' => 'delivery_team',
            'sales' => 'sales_owner',
            default => 'internal',
        };
    }
}
