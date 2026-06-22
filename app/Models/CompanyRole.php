<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'company_id',
        'role_key',
        'notes',
    ];

    protected $casts = [
        'role_key' => 'string',
    ];

    /**
     * Get the tenant that owns this company role
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the company that owns this role
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope to get companies by role
     */
    public function scopeByRole($query, $roleKey)
    {
        return $query->where('role_key', $roleKey);
    }

    /**
     * Scope to get customers
     */
    public function scopeCustomers($query)
    {
        return $query->where('role_key', 'customer');
    }

    /**
     * Scope to get suppliers
     */
    public function scopeSuppliers($query)
    {
        return $query->where('role_key', 'supplier');
    }

    /**
     * Scope to get print partners
     */
    public function scopePrintPartners($query)
    {
        return $query->where('role_key', 'print_fason');
    }

    /**
     * Scope to get delivery partners
     */
    public function scopeDeliveryPartners($query)
    {
        return $query->where('role_key', 'delivery_partner');
    }
}
