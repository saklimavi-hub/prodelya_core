<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'key',
        'value',
        'type',
        'description',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'type' => 'string',
    ];

    /**
     * Get the tenant that owns this setting
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the value cast to the appropriate type
     */
    public function getCastedValueAttribute()
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            'float' => (float) $this->value,
            'json', 'array' => json_decode($this->value, true),
            default => $this->value,
        };
    }

    /**
     * Set the value with proper casting
     */
    public function setCastedValueAttribute($value)
    {
        $this->attributes['value'] = match ($this->type) {
            'boolean' => $value ? '1' : '0',
            'json', 'array' => json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * Scope to get settings by key
     */
    public function scopeByKey($query, $key)
    {
        return $query->where('key', $key);
    }

    /**
     * Scope to get public settings
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope to get private settings
     */
    public function scopePrivate($query)
    {
        return $query->where('is_public', false);
    }

    /**
     * Get or create a setting for a tenant
     */
    public static function getOrCreate($tenantId, $key, $defaultValue = null, $type = 'string')
    {
        $setting = static::where('tenant_account_id', $tenantId)
            ->where('key', $key)
            ->first();

        if (!$setting) {
            $setting = static::create([
                'tenant_account_id' => $tenantId,
                'key' => $key,
                'value' => $defaultValue,
                'type' => $type,
            ]);
        }

        return $setting;
    }

    /**
     * Get a setting value for a tenant
     */
    public static function getValue($tenantId, $key, $defaultValue = null)
    {
        $setting = static::where('tenant_account_id', $tenantId)
            ->where('key', $key)
            ->first();

        return $setting ? $setting->casted_value : $defaultValue;
    }

    /**
     * Set a setting value for a tenant
     */
    public static function setValue($tenantId, $key, $value, $type = null)
    {
        $type = $type ?? match (gettype($value)) {
            'boolean' => 'boolean',
            'integer' => 'integer',
            'double' => 'float',
            'array' => 'array',
            default => 'string',
        };

        return static::updateOrCreate(
            ['tenant_account_id' => $tenantId, 'key' => $key],
            ['value' => $value, 'type' => $type]
        );
    }

    /**
     * Get common system settings with defaults
     */
    public static function getCommonSettings()
    {
        return [
            'default_locale' => ['default' => 'tr', 'type' => 'string'],
            'default_currency' => ['default' => 'TL', 'type' => 'string'],
            'timezone' => ['default' => 'Europe/Istanbul', 'type' => 'string'],
            'number_format_locale' => ['default' => 'tr_TR', 'type' => 'string'],
            'money_format' => ['default' => '1.234,56 TL', 'type' => 'string'],
            'storage_disk' => ['default' => 'local', 'type' => 'string'],
            'work_folder_root_name' => ['default' => 'ISLER', 'type' => 'string'],
            'company_display_name' => ['default' => null, 'type' => 'string'],
            'company_legal_name' => ['default' => null, 'type' => 'string'],
            'company_tax_office' => ['default' => null, 'type' => 'string'],
            'company_tax_number' => ['default' => null, 'type' => 'string'],
            'company_phone' => ['default' => null, 'type' => 'string'],
            'company_email' => ['default' => null, 'type' => 'string'],
            'company_website' => ['default' => null, 'type' => 'string'],
            'company_address' => ['default' => null, 'type' => 'string'],
            'company_city' => ['default' => null, 'type' => 'string'],
            'company_district' => ['default' => null, 'type' => 'string'],
            'company_country' => ['default' => 'Türkiye', 'type' => 'string'],
            'company_postal_code' => ['default' => null, 'type' => 'string'],
            'smtp_enabled' => ['default' => false, 'type' => 'boolean'],
            'smtp_host' => ['default' => null, 'type' => 'string'],
            'smtp_port' => ['default' => 587, 'type' => 'integer'],
            'smtp_username' => ['default' => null, 'type' => 'string'],
            'smtp_password' => ['default' => null, 'type' => 'string'],
            'smtp_encryption' => ['default' => 'tls', 'type' => 'string'],
            'smtp_from_name' => ['default' => null, 'type' => 'string'],
            'smtp_from_email' => ['default' => null, 'type' => 'string'],
            'smtp_reply_to_email' => ['default' => null, 'type' => 'string'],
            'smtp_is_active' => ['default' => false, 'type' => 'boolean'],
            'smtp_test_email' => ['default' => null, 'type' => 'string'],
            'whatsapp_default_country_code' => ['default' => '+90', 'type' => 'string'],
            'whatsapp_sender_label' => ['default' => 'Prodelya', 'type' => 'string'],
            'whatsapp_is_active' => ['default' => false, 'type' => 'boolean'],
            'notification_email_enabled' => ['default' => true, 'type' => 'boolean'],
            'notification_whatsapp_enabled' => ['default' => true, 'type' => 'boolean'],
            'notification_sms_enabled' => ['default' => false, 'type' => 'boolean'],
            'customer_notification_enabled' => ['default' => true, 'type' => 'boolean'],
            'internal_notification_enabled' => ['default' => true, 'type' => 'boolean'],
            'portal_enabled' => ['default' => false, 'type' => 'boolean'],
            'auto_quote_numbering' => ['default' => true, 'type' => 'boolean'],
            'auto_order_numbering' => ['default' => true, 'type' => 'boolean'],
            'require_approval_for_orders' => ['default' => true, 'type' => 'boolean'],
            'allow_negative_stock' => ['default' => false, 'type' => 'boolean'],
            'enable_customer_portal' => ['default' => false, 'type' => 'boolean'],
            'enable_supplier_portal' => ['default' => false, 'type' => 'boolean'],
        ];
    }
}
