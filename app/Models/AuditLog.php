<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'notes',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Get the tenant for this audit log
     */
    public function tenant()
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    /**
     * Get the user who performed this action
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to get logs by action type
     */
    public function scopeByAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope to get logs by entity
     */
    public function scopeByEntity($query, $entityType, $entityId = null)
    {
        $query->where('entity_type', $entityType);
        
        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }
        
        return $query;
    }

    /**
     * Scope to get logs for a specific tenant
     */
    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_account_id', $tenantId);
    }

    /**
     * Scope to get logs by user
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get logs within date range
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Log an audit event
     */
    public static function log(array $data)
    {
        return static::create([
            'tenant_account_id' => $data['tenant_account_id'] ?? null,
            'user_id' => $data['user_id'] ?? auth()->id(),
            'action' => $data['action'],
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null,
            'ip_address' => $data['ip_address'] ?? request()->ip(),
            'user_agent' => $data['user_agent'] ?? request()->userAgent(),
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Log financial data access
     */
    public static function logFinancialAccess($tenantId, $userId, $entityType, $entityId, $details = null)
    {
        return static::log([
            'tenant_account_id' => $tenantId,
            'user_id' => $userId,
            'action' => 'financial_data_access',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'notes' => $details,
        ]);
    }

    /**
     * Log permission violations
     */
    public static function logPermissionViolation($tenantId, $userId, $permission, $entityType = null, $entityId = null)
    {
        return static::log([
            'tenant_account_id' => $tenantId,
            'user_id' => $userId,
            'action' => 'permission_violation',
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'notes' => "Attempted to access without permission: {$permission}",
        ]);
    }

    /**
     * Log module access changes
     */
    public static function logModuleChange($tenantId, $userId, $moduleKey, $isEnabled, $details = null)
    {
        return static::log([
            'tenant_account_id' => $tenantId,
            'user_id' => $userId,
            'action' => $isEnabled ? 'module_enabled' : 'module_disabled',
            'entity_type' => 'tenant_module',
            'entity_id' => $moduleKey,
            'notes' => $details,
        ]);
    }

    /**
     * Get human readable action description
     */
    public function getActionDescriptionAttribute()
    {
        $descriptions = [
            'financial_data_access' => 'Finansal Veri Erişimi',
            'permission_violation' => 'Yetki İhlali',
            'module_enabled' => 'Modül Aktif Edildi',
            'module_disabled' => 'Modül Pasif Edildi',
            'order_created' => 'Sipariş Oluşturuldu',
            'order_updated' => 'Sipariş Güncellendi',
            'order_deleted' => 'Sipariş Silindi',
            'quote_created' => 'Teklif Oluşturuldu',
            'quote_updated' => 'Teklif Güncellendi',
            'quote_deleted' => 'Teklif Silindi',
            'user_login' => 'Kullanıcı Girişi',
            'user_logout' => 'Kullanıcı Çıkışı',
            'settings_updated' => 'Ayarlar Güncellendi',
        ];

        return $descriptions[$this->action] ?? $this->action;
    }
}
