<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'company_id',
        'address_type',
        'title',
        'country',
        'city',
        'district',
        'address',
        'postal_code',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'address_type' => 'string',
    ];

    /**
     * Get the tenant that owns this address
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the company that owns this address
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope to get default addresses
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope to get addresses by type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('address_type', $type);
    }

    /**
     * Scope to get invoice addresses
     */
    public function scopeInvoice($query)
    {
        return $query->where('address_type', 'invoice');
    }

    /**
     * Scope to get delivery addresses
     */
    public function scopeDelivery($query)
    {
        return $query->where('address_type', 'delivery');
    }

    /**
     * Get full address as formatted string
     */
    public function getFullAddressAttribute()
    {
        $parts = array_filter([
            $this->address,
            $this->district,
            $this->city,
            $this->country,
            $this->postal_code
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get address with title
     */
    public function getAddressWithTitleAttribute()
    {
        $title = $this->title ? "{$this->title}: " : '';
        return $title . $this->full_address;
    }
}
