<?php

namespace App\Services\Currency;

use App\Models\ExchangeRate;
use App\Models\TenantAccount;
use App\Models\TenantSetting;
use App\Services\TenantAccessService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class TenantCurrencySettingsService
{
    public const SUPPORTED_CURRENCIES = ['TRY', 'USD', 'EUR'];

    public function __construct(
        private readonly CurrencyCodeNormalizer $normalizer,
        private readonly TenantAccessService $tenantAccessService,
        private readonly ExchangeRateSyncService $exchangeRateSyncService,
    ) {
    }

    public function displayLabel(?string $currency): string
    {
        $code = $this->normalizeCurrency($currency, 'TRY');

        return $code === 'TRY' ? 'TL' : $code;
    }

    public function effectiveSettings(TenantAccount $tenant): array
    {
        $multiCurrencyEnabled = $this->tenantAccessService->canAccessModule($tenant, 'multi_currency');
        $baseCurrency = $this->normalizeCurrency($tenant->default_currency, 'TRY');
        $storedDefault = TenantSetting::getValue($tenant->id, 'default_quote_currency', $multiCurrencyEnabled ? $baseCurrency : 'TRY');
        $enabledCurrencies = $this->normalizeEnabledCurrencies(
            TenantSetting::getValue($tenant->id, 'enabled_quote_currencies', $multiCurrencyEnabled ? self::SUPPORTED_CURRENCIES : ['TRY']),
            $multiCurrencyEnabled
        );

        $defaultQuoteCurrency = $this->normalizeCurrency($storedDefault, $multiCurrencyEnabled ? $baseCurrency : 'TRY');
        if (! in_array($defaultQuoteCurrency, $enabledCurrencies, true)) {
            $defaultQuoteCurrency = $enabledCurrencies[0] ?? 'TRY';
        }

        $supportedRateTypes = array_keys((array) config('prodelya_currency.providers.tcmb.supported_rate_types', [
            'forex_selling' => 'ForexSelling',
        ]));
        $rateSource = (string) TenantSetting::getValue(
            $tenant->id,
            'currency_rate_source',
            (string) config('prodelya_currency.default_rate_source', 'tcmb')
        );
        if (! in_array($rateSource, ['tcmb'], true)) {
            $rateSource = 'tcmb';
        }

        $rateType = (string) TenantSetting::getValue(
            $tenant->id,
            'currency_rate_type',
            (string) config('prodelya_currency.default_rate_type', 'forex_selling')
        );
        if (! in_array($rateType, $supportedRateTypes, true)) {
            $rateType = (string) config('prodelya_currency.default_rate_type', 'forex_selling');
        }

        $staleAfterDays = (int) TenantSetting::getValue(
            $tenant->id,
            'currency_stale_after_days',
            (int) config('prodelya_currency.stale_warning_threshold', 2)
        );
        $staleAfterDays = max(1, min(3, $staleAfterDays));

        $availableRefreshPolicies = $this->availableRefreshPolicies();
        $refreshPolicy = (string) TenantSetting::getValue($tenant->id, 'currency_refresh_policy', array_key_first($availableRefreshPolicies));
        if (! array_key_exists($refreshPolicy, $availableRefreshPolicies)) {
            $refreshPolicy = array_key_first($availableRefreshPolicies);
        }

        return [
            'multi_currency_enabled' => $multiCurrencyEnabled,
            'base_currency' => $baseCurrency,
            'base_currency_label' => $this->displayLabel($baseCurrency),
            'default_quote_currency' => $defaultQuoteCurrency,
            'default_quote_currency_label' => $this->displayLabel($defaultQuoteCurrency),
            'enabled_quote_currencies' => $enabledCurrencies,
            'enabled_quote_currency_labels' => array_map(fn (string $currency) => $this->displayLabel($currency), $enabledCurrencies),
            'rate_source' => $rateSource,
            'rate_source_label' => $this->rateSourceLabel($rateSource),
            'rate_type' => $rateType,
            'rate_type_label' => $this->rateTypeLabel($rateType),
            'stale_after_days' => $staleAfterDays,
            'refresh_policy' => $refreshPolicy,
            'refresh_policy_label' => $availableRefreshPolicies[$refreshPolicy],
            'available_refresh_policies' => $availableRefreshPolicies,
        ];
    }

    public function quoteCurrencyOptions(TenantAccount $tenant, ?string $currentCurrency = null): array
    {
        $settings = $this->effectiveSettings($tenant);
        $currencies = $settings['enabled_quote_currencies'];
        $normalizedCurrent = $this->normalizeCurrency($currentCurrency, null);

        if ($normalizedCurrent && ! in_array($normalizedCurrent, $currencies, true)) {
            $currencies[] = $normalizedCurrent;
        }

        return array_map(function (string $currency) use ($settings, $normalizedCurrent): array {
            return [
                'value' => $currency,
                'label' => $this->displayLabel($currency),
                'current_only' => $normalizedCurrent === $currency && ! in_array($currency, $settings['enabled_quote_currencies'], true),
            ];
        }, array_values(array_unique($currencies)));
    }

    public function resolveDefaultQuoteCurrency(TenantAccount $tenant): string
    {
        return (string) $this->effectiveSettings($tenant)['default_quote_currency'];
    }

    public function allowsQuoteCurrencySelection(TenantAccount $tenant, ?string $currency, ?string $currentQuoteCurrency = null): bool
    {
        $normalizedCurrency = $this->normalizeCurrency($currency, null);
        if ($normalizedCurrency === null) {
            return false;
        }

        $normalizedCurrent = $this->normalizeCurrency($currentQuoteCurrency, null);
        if ($normalizedCurrent !== null && $normalizedCurrent === $normalizedCurrency) {
            return true;
        }

        return in_array($normalizedCurrency, $this->effectiveSettings($tenant)['enabled_quote_currencies'], true);
    }

    public function validateAndNormalize(TenantAccount $tenant, array $input): array
    {
        $settings = $this->effectiveSettings($tenant);
        $multiCurrencyEnabled = (bool) $settings['multi_currency_enabled'];

        $baseCurrency = $this->normalizeCurrency($input['base_currency'] ?? null, $settings['base_currency']) ?? 'TRY';
        $enabled = $this->normalizeEnabledCurrencies($input['enabled_quote_currencies'] ?? [], $multiCurrencyEnabled);
        $defaultQuoteCurrency = $this->normalizeCurrency($input['default_quote_currency'] ?? null, $settings['default_quote_currency']);
        if (! in_array($defaultQuoteCurrency, $enabled, true)) {
            $defaultQuoteCurrency = $enabled[0] ?? 'TRY';
        }

        $availableRefreshPolicies = $this->availableRefreshPolicies();

        return [
            'base_currency' => $baseCurrency,
            'default_quote_currency' => $defaultQuoteCurrency,
            'enabled_quote_currencies' => $enabled,
            'currency_rate_source' => (string) Arr::get($input, 'currency_rate_source', $settings['rate_source']),
            'currency_rate_type' => (string) Arr::get($input, 'currency_rate_type', $settings['rate_type']),
            'currency_stale_after_days' => max(1, min(3, (int) Arr::get($input, 'currency_stale_after_days', $settings['stale_after_days']))),
            'currency_refresh_policy' => (string) Arr::get($input, 'currency_refresh_policy', array_key_first($availableRefreshPolicies)),
        ];
    }

    public function persist(TenantAccount $tenant, array $settings): void
    {
        $baseCurrency = $this->normalizeCurrency((string) ($settings['base_currency'] ?? $tenant->default_currency), 'TRY') ?? 'TRY';

        if ($tenant->default_currency !== $baseCurrency) {
            $tenant->forceFill(['default_currency' => $baseCurrency])->save();
        }

        TenantSetting::setValue($tenant->id, 'default_currency', $baseCurrency, 'string');
        TenantSetting::setValue($tenant->id, 'default_quote_currency', (string) $settings['default_quote_currency'], 'string');
        TenantSetting::setValue($tenant->id, 'enabled_quote_currencies', array_values($settings['enabled_quote_currencies'] ?? []), 'array');
        TenantSetting::setValue($tenant->id, 'currency_rate_source', (string) $settings['currency_rate_source'], 'string');
        TenantSetting::setValue($tenant->id, 'currency_rate_type', (string) $settings['currency_rate_type'], 'string');
        TenantSetting::setValue($tenant->id, 'currency_stale_after_days', (int) $settings['currency_stale_after_days'], 'integer');
        TenantSetting::setValue($tenant->id, 'currency_refresh_policy', (string) $settings['currency_refresh_policy'], 'string');
    }

    public function latestRates(TenantAccount $tenant): array
    {
        $settings = $this->effectiveSettings($tenant);
        $rows = [];

        foreach (['USD', 'EUR'] as $sourceCurrency) {
            $record = ExchangeRate::query()
                ->where('provider', $settings['rate_source'])
                ->where('rate_type', $settings['rate_type'])
                ->where('source_currency', $sourceCurrency)
                ->where('target_currency', 'TRY')
                ->orderByDesc('rate_date')
                ->orderByDesc('fetched_at')
                ->first();

            $rows[] = $this->mapRateRow($record, $sourceCurrency, $settings['stale_after_days']);
        }

        return $rows;
    }

    public function refreshRates(TenantAccount $tenant): array
    {
        $settings = $this->effectiveSettings($tenant);

        return $this->exchangeRateSyncService->sync(
            now()->toDateString(),
            $settings['rate_source'],
            $settings['rate_type'],
            false,
            null,
            false
        );
    }

    public function availableRefreshPolicies(): array
    {
        $policies = [
            'manual' => 'Teklif sırasında kullanıcı yenilesin',
        ];

        if ((bool) config('prodelya_currency.sync.schedule.enabled', false)) {
            $policies['scheduled_daily'] = 'Günlük otomatik güncelle';
        }

        return $policies;
    }

    public function rateSourceLabel(string $source): string
    {
        return match ($source) {
            'tcmb' => 'TCMB',
            default => strtoupper($source),
        };
    }

    public function rateTypeLabel(string $type): string
    {
        return match ($type) {
            'forex_selling' => 'Döviz Satış',
            'forex_buying' => 'Döviz Alış',
            default => $type,
        };
    }

    private function normalizeEnabledCurrencies(mixed $value, bool $multiCurrencyEnabled): array
    {
        if (! $multiCurrencyEnabled) {
            return ['TRY'];
        }

        $values = is_array($value) ? $value : [$value];
        $normalized = collect($values)
            ->map(fn ($currency) => $this->normalizeCurrency(is_string($currency) ? $currency : null, null))
            ->filter(fn ($currency) => $currency !== null && in_array($currency, self::SUPPORTED_CURRENCIES, true))
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return self::SUPPORTED_CURRENCIES;
        }

        return $normalized;
    }

    private function normalizeCurrency(?string $currency, ?string $fallback): ?string
    {
        $candidate = trim((string) ($currency ?? ''));
        if ($candidate === '') {
            return $fallback;
        }

        try {
            $normalized = $this->normalizer->normalize($candidate);
        } catch (\Throwable) {
            $normalized = strtoupper($candidate);
        }

        return in_array($normalized, self::SUPPORTED_CURRENCIES, true) ? $normalized : $fallback;
    }

    private function mapRateRow(?ExchangeRate $record, string $sourceCurrency, int $staleAfterDays): array
    {
        if (! $record) {
            return [
                'pair' => $sourceCurrency . '/TL',
                'source_currency' => $sourceCurrency,
                'value' => null,
                'value_label' => 'Kayıt yok',
                'provider_label' => 'TCMB',
                'rate_date_label' => '-',
                'status' => 'missing',
                'status_label' => 'Kayıt yok',
                'status_tone' => 'slate',
                'fetched_at_label' => null,
            ];
        }

        $today = Carbon::today();
        $rateDate = $record->rate_date instanceof Carbon
            ? $record->rate_date->copy()
            : Carbon::parse((string) $record->rate_date);
        $isStale = $rateDate->diffInDays($today) >= $staleAfterDays;

        return [
            'pair' => $sourceCurrency . '/TL',
            'source_currency' => $sourceCurrency,
            'value' => (float) $record->rate,
            'value_label' => number_format((float) $record->rate, 4, ',', '.'),
            'provider_label' => $this->rateSourceLabel((string) $record->provider),
            'rate_date_label' => $rateDate->format('d.m.Y'),
            'status' => $isStale ? 'stale' : 'current',
            'status_label' => $isStale ? 'Eski' : 'Guncel',
            'status_tone' => $isStale ? 'amber' : 'green',
            'fetched_at_label' => $record->fetched_at?->format('d.m.Y H:i'),
        ];
    }
}




