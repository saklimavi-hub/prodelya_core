<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'panel_subdomain',
        'custom_domain',
        'portal_domain',
        'status',
        'package_key',
        'default_locale',
        'default_currency',
        'timezone',
        'number_format_locale',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    // TODO: Add relationships with tenant modules, users, companies, orders, etc.
    // TODO: Add scope methods for active tenants
    // TODO: Add methods for domain resolution
    // TODO: Add validation rules for unique domains/subdomains
    
    /**
     * Get all modules for this tenant
     */
    public function modules()
    {
        return $this->hasMany(TenantModule::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_key', 'key');
    }

    /**
     * Get all companies for this tenant
     */
    public function companies()
    {
        return $this->hasMany(Company::class);
    }

    public function currentAccounts()
    {
        return $this->hasMany(CurrentAccount::class);
    }

    public function currentAccountTransactions()
    {
        return $this->hasMany(CurrentAccountTransaction::class);
    }

    public function billingEntries()
    {
        return $this->hasMany(TenantBillingEntry::class);
    }

    public function paymentCheckoutSessions()
    {
        return $this->hasMany(PaymentCheckoutSession::class);
    }

    public function paymentGatewayCredentials()
    {
        return $this->hasMany(PaymentGatewayCredential::class);
    }

    public function paymentWebhookLogs()
    {
        return $this->hasMany(PaymentWebhookLog::class);
    }

    public function packageUpgradeRequests()
    {
        return $this->hasMany(TenantPackageUpgradeRequest::class);
    }

    public function printSettings()
    {
        return $this->hasMany(TenantPrintSetting::class);
    }

    /**
     * Get all orders for this tenant
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get number sequences for this tenant
     */
    public function numberSequences()
    {
        return $this->hasMany(TenantNumberSequence::class);
    }

    /**
     * Get audit logs for this tenant
     */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Get settings for this tenant
     */
    public function settings()
    {
        return $this->hasMany(TenantSetting::class);
    }

    public function notificationTemplates()
    {
        return $this->hasMany(NotificationTemplate::class);
    }

    public function notificationLogs()
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * Get supplier access rows for this tenant.
     */
    public function supplierAccesses()
    {
        return $this->hasMany(TenantSupplierAccess::class);
    }

    public function supplierProcurementRequests()
    {
        return $this->hasMany(SupplierProcurementRequest::class);
    }

    public function supplierProcurementRequestItems()
    {
        return $this->hasMany(SupplierProcurementRequestItem::class);
    }

    public function catalogProducts()
    {
        return $this->hasMany(TenantCatalogProduct::class);
    }

    /**
     * Get modules for product data hub capabilities.
     */
    public function productDataHubModules()
    {
        return $this->modules()->whereIn('module_key', [
            'product_data_hub',
            'advanced_catalog',
            'supplier_feed',
            'export_web_feed',
        ]);
    }

    /**
     * Scope to get only active tenants
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
