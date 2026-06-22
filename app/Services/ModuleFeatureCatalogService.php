<?php

namespace App\Services;

class ModuleFeatureCatalogService
{
    public function modules(): array
    {
        $modules = config('prodelya_modules.modules', []);

        uasort($modules, fn (array $left, array $right) => ($left['sort_order'] ?? 0) <=> ($right['sort_order'] ?? 0));

        return $modules;
    }

    public function features(?string $moduleKey = null): array
    {
        $features = config('prodelya_modules.features', []);

        if ($moduleKey === null) {
            return $features;
        }

        $normalizedModuleKey = $this->normalizeModuleKey($moduleKey);

        return $features[$normalizedModuleKey] ?? [];
    }

    public function getModule(string $key): ?array
    {
        $normalizedKey = $this->normalizeModuleKey($key);
        $module = config("prodelya_modules.modules.{$normalizedKey}");

        return is_array($module) ? $module : null;
    }

    public function getFeature(string $key): ?array
    {
        $normalizedKey = $this->normalizeFeatureKey($key);

        foreach ($this->features() as $moduleFeatures) {
            if (isset($moduleFeatures[$normalizedKey]) && is_array($moduleFeatures[$normalizedKey])) {
                return $moduleFeatures[$normalizedKey];
            }
        }

        return null;
    }

    public function normalizeModuleKey(string $key): string
    {
        $normalizedKey = $this->cleanKey($key);
        $aliases = config('prodelya_modules.aliases.modules', []);

        return $aliases[$normalizedKey] ?? $normalizedKey;
    }

    public function normalizeFeatureKey(string $key): string
    {
        $normalizedKey = $this->cleanKey($key);
        $aliases = config('prodelya_modules.aliases.features', []);

        return $aliases[$normalizedKey] ?? $normalizedKey;
    }

    public function isCoreModule(string $key): bool
    {
        return ($this->getModule($key)['status'] ?? null) === 'core';
    }

    public function isActiveModule(string $key): bool
    {
        return in_array($this->getModule($key)['status'] ?? null, ['core', 'active'], true);
    }

    public function isPlannedModule(string $key): bool
    {
        return ($this->getModule($key)['status'] ?? null) === 'planned';
    }

    public function defaultEnabledModules(): array
    {
        $modules = [];

        foreach ($this->modules() as $key => $module) {
            if (($module['default_enabled'] ?? false) === true) {
                $modules[] = $key;
            }
        }

        return $modules;
    }

    public function defaultEnabledFeatures(): array
    {
        $features = [];

        foreach ($this->features() as $moduleFeatures) {
            foreach ($moduleFeatures as $key => $feature) {
                if (($feature['default_enabled'] ?? false) === true) {
                    $features[] = $key;
                }
            }
        }

        return $features;
    }

    public function moduleOptionsForAdmin(): array
    {
        return array_values(array_map(function (array $module): array {
            return [
                'key' => $module['key'],
                'label' => $module['label'] ?? $module['name'] ?? $module['key'],
                'description' => $module['description'] ?? null,
                'category' => $module['category'] ?? null,
                'status' => $module['status'] ?? 'passive',
                'is_core' => (bool) ($module['is_core'] ?? false),
                'default_enabled' => (bool) ($module['default_enabled'] ?? false),
                'requires_package' => (bool) ($module['requires_package'] ?? false),
                'sort_order' => (int) ($module['sort_order'] ?? 0),
            ];
        }, $this->modules()));
    }

    public function featureOptionsForAdmin(?string $moduleKey = null): array
    {
        $moduleFeatures = $moduleKey === null
            ? $this->flattenFeatures()
            : $this->features($moduleKey);

        uasort($moduleFeatures, fn (array $left, array $right) => ($left['sort_order'] ?? 0) <=> ($right['sort_order'] ?? 0));

        return array_values(array_map(function (array $feature): array {
            return [
                'key' => $feature['key'],
                'label' => $feature['label'] ?? $feature['name'] ?? $feature['key'],
                'description' => $feature['description'] ?? null,
                'status' => $feature['status'] ?? 'passive',
                'default_enabled' => (bool) ($feature['default_enabled'] ?? false),
                'sort_order' => (int) ($feature['sort_order'] ?? 0),
            ];
        }, $moduleFeatures));
    }

    public function statusValues(): array
    {
        return config('prodelya_modules.status_values', []);
    }

    private function flattenFeatures(): array
    {
        $flattened = [];

        foreach ($this->features() as $moduleFeatures) {
            foreach ($moduleFeatures as $key => $feature) {
                $flattened[$key] = $feature;
            }
        }

        return $flattened;
    }

    private function cleanKey(string $key): string
    {
        $clean = strtolower(trim($key));
        $clean = preg_replace('/[^a-z0-9_]+/', '_', $clean) ?? $clean;

        return trim($clean, '_');
    }
}
