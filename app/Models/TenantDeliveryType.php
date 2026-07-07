<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TenantDeliveryType extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_account_id',
        'name',
        'code',
        'description',
        'is_active',
        'sort_order',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantAccount::class, 'tenant_account_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'delivery_type_id');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_account_id', $tenantId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public static function defaultDefinitions(): array
    {
        return [
            ['name' => 'Ofis Teslim', 'description' => 'Teslimat abone firmanın ofisine yapılır.', 'sort_order' => 10, 'is_default' => true],
            ['name' => 'Kargo Karşı Ödemeli', 'description' => 'Kargo ücreti alıcı tarafından karşılanır.', 'sort_order' => 20, 'is_default' => false],
            ['name' => 'Kargo Gönderimi', 'description' => 'Teslimat kargo ile sevk edilir.', 'sort_order' => 30, 'is_default' => false],
            ['name' => 'Ambar', 'description' => 'Ambar sevkiyatı ile teslim edilir.', 'sort_order' => 40, 'is_default' => false],
            ['name' => 'Kurye', 'description' => 'Kurye ile teslim edilir.', 'sort_order' => 50, 'is_default' => false],
            ['name' => 'Elden Teslim', 'description' => 'Teslimat elden yapılır.', 'sort_order' => 60, 'is_default' => false],
            ['name' => 'Müşteri Teslim Alacak', 'description' => 'Ürün müşterinin teslim alması için hazır bekler.', 'sort_order' => 70, 'is_default' => false],
        ];
    }

    public static function makeCode(string $value): string
    {
        $code = Str::slug(Str::lower(trim($value)), '-');

        return $code !== '' ? $code : 'delivery-type';
    }
}
