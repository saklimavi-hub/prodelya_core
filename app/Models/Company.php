<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'legal_name',
        'short_name',
        'tax_office',
        'tax_number',
        'email',
        'phone',
        'mobile',
        'website',
        'status',
        'risk_status',
        'portal_enabled',
        'notes',
    ];

    protected $casts = [
        'portal_enabled' => 'boolean',
        'status' => 'string',
    ];

    // TODO: Add relationships with contacts, addresses, orders, etc.
    // TODO: Add scope methods for active companies
    // TODO: Add validation for tax numbers and email formats
    
    /**
     * Get the tenant that owns this company
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the contacts for this company
     */
    public function contacts()
    {
        return $this->hasMany(CompanyContact::class);
    }

    public function portalUsers()
    {
        return $this->hasMany(CustomerPortalUser::class);
    }

    /**
     * Get the addresses for this company
     */
    public function addresses()
    {
        return $this->hasMany(CompanyAddress::class);
    }

    /**
     * Get the roles for this company
     */
    public function companyRoles()
    {
        return $this->hasMany(CompanyRole::class);
    }

    public function currentAccountLink()
    {
        return $this->hasOne(CurrentAccountLink::class, 'link_id')
            ->where('link_type', CurrentAccountLink::LINK_COMPANY)
            ->whereColumn('tenant_account_id', 'companies.tenant_account_id');
    }

    public function currentAccount()
    {
        return $this->hasOneThrough(
            CurrentAccount::class,
            CurrentAccountLink::class,
            'link_id',
            'id',
            'id',
            'current_account_id'
        )->where('current_account_links.link_type', CurrentAccountLink::LINK_COMPANY)
         ->whereColumn('current_account_links.tenant_account_id', 'companies.tenant_account_id');
    }

    /**
     * Get orders where this company is the customer
     */
    public function customerOrders()
    {
        return $this->hasMany(Order::class, 'customer_company_id');
    }

    public function orderPayments()
    {
        return $this->hasMany(OrderPayment::class, 'customer_company_id');
    }

    /**
     * Get orders where this company is the supplier
     */
    public function supplierOrders()
    {
        return $this->hasMany(OrderItem::class, 'supplier_id');
    }

    /**
     * Scope to get only active companies
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get companies with portal access
     */
    public function scopePortalEnabled($query)
    {
        return $query->where('portal_enabled', true);
    }

    /**
     * Check if company has a specific role
     */
    public function hasRole($roleKey)
    {
        return $this->companyRoles()->where('role_key', $roleKey)->exists();
    }

    /**
     * Get primary contact for this company
     */
    public function getPrimaryContact()
    {
        return $this->contacts()->where('is_primary', true)->first();
    }

    /**
     * Get default address for this company
     */
    public function getDefaultAddress()
    {
        return $this->addresses()->where('is_default', true)->first();
    }

    /**
     * Get default billing address for this company
     */
    public function getDefaultBillingAddress()
    {
        return $this->addresses()->where('address_type', 'billing')->where('is_default', true)->first();
    }

    /**
     * Get default delivery address for this company
     */
    public function getDefaultDeliveryAddress()
    {
        return $this->addresses()->where('address_type', 'delivery')->where('is_default', true)->first();
    }

    /**
     * Check if company is a customer
     */
    public function isCustomer()
    {
        return $this->hasRole('customer');
    }

    /**
     * Check if company is a supplier
     */
    public function isSupplier()
    {
        return $this->hasRole('supplier');
    }

    /**
     * Check if company is a print fason
     */
    public function isPrintFason()
    {
        return $this->hasRole('print_fason');
    }

    /**
     * Check if company is a delivery partner
     */
    public function isDeliveryPartner()
    {
        return $this->hasRole('delivery_partner');
    }

    /**
     * Get all role keys for this company
     */
    public function getRoleKeys()
    {
        return $this->companyRoles()->pluck('role_key')->toArray();
    }

    /**
     * Get formatted role names for display
     */
    public function getRoleNames()
    {
        $roleNames = [];
        foreach ($this->companyRoles as $companyRole) {
            $roleNames[] = match($companyRole->role_key) {
                'customer' => 'Müşteri',
                'supplier' => 'Tedarikçi',
                'print_fason' => 'Fason Baskı Firması',
                'production_partner' => 'Fason Üretim Firması',
                'delivery_partner' => 'Nakliye / Kargo',
                'other' => 'Diğer',
                default => ucfirst($companyRole->role_key),
            };
        }
        return $roleNames;
    }

    /**
     * Get role badge colors
     */
    public function getRoleBadgeColors()
    {
        $colors = [];
        foreach ($this->companyRoles as $companyRole) {
            $colors[] = match($companyRole->role_key) {
                'customer' => 'green',
                'supplier' => 'blue',
                'print_fason' => 'purple',
                'production_partner' => 'amber',
                'delivery_partner' => 'orange',
                'other' => 'gray',
                default => 'gray',
            };
        }
        return $colors;
    }

    /**
     * Scope to get companies by role
     */
    public function scopeByRole($query, $roleKey)
    {
        return $query->whereHas('companyRoles', function ($q) use ($roleKey) {
            $q->where('role_key', $roleKey);
        });
    }

    /**
     * Scope to get companies by risk status
     */
    public function scopeByRiskStatus($query, $riskStatus)
    {
        return $query->where('risk_status', $riskStatus);
    }

    /**
     * Scope to search companies by name or tax number
     */
    public function scopeSearch($query, $searchTerm)
    {
        return $query->where(function ($q) use ($searchTerm) {
            $q->where('legal_name', 'like', "%{$searchTerm}%")
              ->orWhere('short_name', 'like', "%{$searchTerm}%")
              ->orWhere('tax_number', 'like', "%{$searchTerm}%")
              ->orWhere('email', 'like', "%{$searchTerm}%");
        });
    }
}
