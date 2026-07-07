<?php

namespace App\Services;

use App\Models\StandardPrintType;
use App\Models\TenantPrintOption;
use App\Models\TenantPrintSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TenantPrintOptionService
{
    public function ensureDefaultsForSetting(TenantPrintSetting $setting): array
    {
        $definitions = $this->definitionsForSetting($setting);
        $created = 0;
        $skipped = 0;

        foreach ($definitions as $index => $definition) {
            $code = (string) ($definition['code'] ?? Str::slug((string) ($definition['name'] ?? 'option')));

            $existing = TenantPrintOption::query()
                ->where('tenant_account_id', $setting->tenant_account_id)
                ->where('tenant_print_setting_id', $setting->id)
                ->where('code', $code)
                ->first();

            if ($existing) {
                $skipped++;
                continue;
            }

            TenantPrintOption::query()->create([
                'tenant_account_id' => $setting->tenant_account_id,
                'tenant_print_setting_id' => $setting->id,
                'standard_print_type_id' => $setting->standard_print_type_id,
                'name' => $definition['name'],
                'code' => $code,
                'description' => $definition['description'] ?? null,
                'is_active' => true,
                'sort_order' => $definition['sort_order'] ?? (($index + 1) * 10),
                'is_default' => (bool) ($definition['is_default'] ?? false),
                'default_unit_price' => $definition['default_unit_price'] ?? null,
                'requires_setup' => (bool) ($definition['requires_setup'] ?? false),
                'setup_type' => $definition['setup_type'] ?? null,
                'setup_status_default' => $definition['setup_status_default'] ?? null,
            ]);

            $created++;
        }

        $this->normalizeDefaultOption($setting);

        return [
            'created' => $created,
            'skipped_existing' => $skipped,
        ];
    }

    public function normalizeDefaultOption(TenantPrintSetting $setting): void
    {
        $options = TenantPrintOption::query()
            ->where('tenant_account_id', $setting->tenant_account_id)
            ->where('tenant_print_setting_id', $setting->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($options->isEmpty()) {
            return;
        }

        $default = $options->firstWhere('is_default', true) ?: $options->firstWhere('is_active', true) ?: $options->first();

        TenantPrintOption::query()
            ->where('tenant_account_id', $setting->tenant_account_id)
            ->where('tenant_print_setting_id', $setting->id)
            ->update(['is_default' => false]);

        $default?->forceFill(['is_default' => true])->save();
    }

    public function optionsForSetting(TenantPrintSetting $setting, array $includeIds = []): Collection
    {
        $this->ensureDefaultsForSetting($setting);

        return TenantPrintOption::query()
            ->where('tenant_account_id', $setting->tenant_account_id)
            ->where('tenant_print_setting_id', $setting->id)
            ->where(function ($query) use ($includeIds): void {
                $query->where('is_active', true);

                if (!empty($includeIds)) {
                    $query->orWhereIn('id', collect($includeIds)->map(fn ($id) => (int) $id)->all());
                }
            })
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function definitionsForSetting(TenantPrintSetting $setting): array
    {
        $code = (string) ($setting->standardPrintType?->code ?: '');

        return match ($code) {
            'HOT_STAMPING' => [
                ['name' => 'Klişeli sıcak baskı', 'code' => 'hot-stamping-cliche', 'is_default' => true, 'requires_setup' => true, 'setup_type' => 'cliche', 'setup_status_default' => 'Yeni üretilecek'],
                ['name' => 'Mevcut klişe kullanılacak', 'code' => 'hot-stamping-reuse', 'requires_setup' => true, 'setup_type' => 'cliche', 'setup_status_default' => 'Mevcut kullanılacak'],
                ['name' => 'Yeni klişe üretilecek', 'code' => 'hot-stamping-new-cliche', 'requires_setup' => true, 'setup_type' => 'cliche', 'setup_status_default' => 'Yeni üretilecek'],
            ],
            'UV_PRINT' => [
                ['name' => 'Tek taraf UV baskı', 'code' => 'uv-front', 'is_default' => true],
                ['name' => 'Çift taraf UV baskı', 'code' => 'uv-double'],
                ['name' => 'Tam renk UV baskı', 'code' => 'uv-full-color'],
            ],
            'LASER_PRINT' => [
                ['name' => 'Tek pozisyon lazer', 'code' => 'laser-single', 'is_default' => true],
                ['name' => 'İsim baskılı lazer', 'code' => 'laser-name'],
                ['name' => 'Logo lazer kazıma', 'code' => 'laser-logo'],
            ],
            'PAD_PRINT' => [
                ['name' => 'Tek renk tampon baskı', 'code' => 'pad-single', 'is_default' => true],
                ['name' => 'Çift renk tampon baskı', 'code' => 'pad-double'],
            ],
            'SCREEN_PRINT' => [
                ['name' => 'Tek renk serigrafi', 'code' => 'screen-single', 'is_default' => true, 'requires_setup' => true, 'setup_type' => 'film', 'setup_status_default' => 'Yeni üretilecek'],
                ['name' => 'Çok renk serigrafi', 'code' => 'screen-multi', 'requires_setup' => true, 'setup_type' => 'film', 'setup_status_default' => 'Yeni üretilecek'],
            ],
            'DTF' => [
                ['name' => 'Tek pozisyon', 'code' => 'dtf-single', 'is_default' => true],
                ['name' => 'Çok pozisyon', 'code' => 'dtf-multi'],
            ],
            'TRANSFER_PRINT' => [
                ['name' => 'Tek pozisyon', 'code' => 'transfer-single', 'is_default' => true],
                ['name' => 'Çok pozisyon', 'code' => 'transfer-multi'],
            ],
            default => $this->fallbackDefinitionsFromName((string) ($setting->standardPrintType?->name ?: $setting->displayName())),
        };
    }

    private function fallbackDefinitionsFromName(string $name): array
    {
        return match ($name) {
            'UV Baskı' => [
                ['name' => 'Tek taraf UV baskı', 'code' => 'uv-front', 'is_default' => true],
                ['name' => 'Çift taraf UV baskı', 'code' => 'uv-double'],
                ['name' => 'Tam renk UV baskı', 'code' => 'uv-full-color'],
            ],
            'Lazer Baskı', 'Lazer' => [
                ['name' => 'Tek pozisyon lazer', 'code' => 'laser-single', 'is_default' => true],
                ['name' => 'İsim baskılı lazer', 'code' => 'laser-name'],
                ['name' => 'Logo lazer kazıma', 'code' => 'laser-logo'],
            ],
            'Serigrafi' => [
                ['name' => 'Tek renk serigrafi', 'code' => 'screen-single', 'is_default' => true, 'requires_setup' => true, 'setup_type' => 'film', 'setup_status_default' => 'Yeni üretilecek'],
                ['name' => 'Çok renk serigrafi', 'code' => 'screen-multi', 'requires_setup' => true, 'setup_type' => 'film', 'setup_status_default' => 'Yeni üretilecek'],
            ],
            'Tampon Baskı' => [
                ['name' => 'Tek renk tampon baskı', 'code' => 'pad-single', 'is_default' => true],
                ['name' => 'Çift renk tampon baskı', 'code' => 'pad-double'],
            ],
            'Sıcak Baskı' => [
                ['name' => 'Klişeli sıcak baskı', 'code' => 'hot-stamping-cliche', 'is_default' => true, 'requires_setup' => true, 'setup_type' => 'cliche', 'setup_status_default' => 'Yeni üretilecek'],
                ['name' => 'Mevcut klişe kullanılacak', 'code' => 'hot-stamping-reuse', 'requires_setup' => true, 'setup_type' => 'cliche', 'setup_status_default' => 'Mevcut kullanılacak'],
                ['name' => 'Yeni klişe üretilecek', 'code' => 'hot-stamping-new-cliche', 'requires_setup' => true, 'setup_type' => 'cliche', 'setup_status_default' => 'Yeni üretilecek'],
            ],
            'DTF', 'Transfer Baskı' => [
                ['name' => 'Tek pozisyon', 'code' => Str::slug($name) . '-single', 'is_default' => true],
                ['name' => 'Çok pozisyon', 'code' => Str::slug($name) . '-multi'],
            ],
            default => [
                ['name' => 'Genel baskı seçeneği', 'code' => Str::slug($name) . '-default', 'is_default' => true],
            ],
        };
    }
}
