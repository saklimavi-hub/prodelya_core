<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'company_id',
        'name',
        'title',
        'email',
        'phone',
        'mobile',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /**
     * Get the tenant that owns this contact
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the company that owns this contact
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function portalUsers()
    {
        return $this->hasMany(CustomerPortalUser::class);
    }

    public function primaryPortalUser()
    {
        return $this->hasOne(CustomerPortalUser::class)->oldestOfMany();
    }

    /**
     * Scope to get primary contacts
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope to search contacts by name or email
     */
    public function scopeSearch($query, $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%");
        });
    }

    /**
     * Get full name with title
     */
    public function getFullnameWithTitleAttribute()
    {
        return $this->title ? "{$this->title} {$this->name}" : $this->name;
    }
}
