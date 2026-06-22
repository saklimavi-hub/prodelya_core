<?php

namespace App\Services;

use App\Models\TenantAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantCompanyProfileService
{
    private const SETTINGS = [
        'company_display_name' => [
            'type' => 'string',
            'description' => 'Tenant firma profilinde görünen ad',
        ],
        'company_legal_name' => [
            'type' => 'string',
            'description' => 'Tenant firma profilinde kullanılan yasal ünvan',
        ],
        'company_tax_office' => [
            'type' => 'string',
            'description' => 'Tenant firma profili vergi dairesi',
        ],
        'company_tax_number' => [
            'type' => 'string',
            'description' => 'Tenant firma profili vergi numarası',
        ],
        'company_phone' => [
            'type' => 'string',
            'description' => 'Tenant firma profili telefon numarası',
        ],
        'company_email' => [
            'type' => 'string',
            'description' => 'Tenant firma profili e-posta adresi',
        ],
        'company_website' => [
            'type' => 'string',
            'description' => 'Tenant firma profili web sitesi',
        ],
        'company_address' => [
            'type' => 'string',
            'description' => 'Tenant firma profili açık adresi',
        ],
        'company_city' => [
            'type' => 'string',
            'description' => 'Tenant firma profili il bilgisi',
        ],
        'company_district' => [
            'type' => 'string',
            'description' => 'Tenant firma profili ilçe bilgisi',
        ],
        'company_country' => [
            'type' => 'string',
            'description' => 'Tenant firma profili ülke bilgisi',
        ],
        'company_postal_code' => [
            'type' => 'string',
            'description' => 'Tenant firma profili posta kodu',
        ],
    ];

    public function getProfile(TenantAccount $tenant): array
    {
        $settings = $tenant->settings()
            ->whereIn('key', array_keys(self::SETTINGS))
            ->pluck('value', 'key');

        $displayName = $this->cleanValue($settings->get('company_display_name'))
            ?: $this->cleanValue($tenant->legal_name)
            ?: $this->cleanValue($tenant->name)
            ?: 'Prodelya';

        $legalName = $this->cleanValue($settings->get('company_legal_name'))
            ?: $this->cleanValue($tenant->legal_name)
            ?: null;

        $website = $this->normalizeWebsite($this->cleanValue($settings->get('company_website')));
        $country = $this->cleanValue($settings->get('company_country')) ?: 'Türkiye';
        $address = $this->cleanValue($settings->get('company_address'));
        $district = $this->cleanValue($settings->get('company_district'));
        $city = $this->cleanValue($settings->get('company_city'));
        $postalCode = $this->cleanValue($settings->get('company_postal_code'));

        return [
            'display_name' => $displayName,
            'legal_name' => $legalName,
            'tax_office' => $this->cleanValue($settings->get('company_tax_office')),
            'tax_number' => $this->cleanValue($settings->get('company_tax_number')),
            'phone' => $this->cleanValue($settings->get('company_phone')),
            'email' => $this->cleanValue($settings->get('company_email')),
            'website' => $website,
            'address' => $address,
            'district' => $district,
            'city' => $city,
            'country' => $country,
            'postal_code' => $postalCode,
            'full_address' => $this->buildFullAddress($address, $district, $city, $postalCode, $country),
            'logo_url' => null,
        ];
    }

    public function updateProfile(TenantAccount $tenant, array $attributes): void
    {
        $normalized = [
            'company_display_name' => $this->cleanValue($attributes['display_name'] ?? null),
            'company_legal_name' => $this->cleanValue($attributes['legal_name'] ?? null),
            'company_tax_office' => $this->cleanValue($attributes['tax_office'] ?? null),
            'company_tax_number' => $this->cleanValue($attributes['tax_number'] ?? null),
            'company_phone' => $this->cleanValue($attributes['phone'] ?? null),
            'company_email' => $this->cleanValue($attributes['email'] ?? null),
            'company_website' => $this->normalizeWebsite($this->cleanValue($attributes['website'] ?? null)),
            'company_address' => $this->cleanValue($attributes['address'] ?? null),
            'company_city' => $this->cleanValue($attributes['city'] ?? null),
            'company_district' => $this->cleanValue($attributes['district'] ?? null),
            'company_country' => $this->cleanValue($attributes['country'] ?? null) ?: 'Türkiye',
            'company_postal_code' => $this->cleanValue($attributes['postal_code'] ?? null),
        ];

        DB::transaction(function () use ($tenant, $normalized): void {
            foreach ($normalized as $key => $value) {
                $tenant->settings()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $value ?? '',
                        'type' => self::SETTINGS[$key]['type'],
                        'description' => self::SETTINGS[$key]['description'],
                        'is_public' => false,
                    ]
                );
            }

            $tenant->forceFill([
                'name' => $normalized['company_display_name'] ?: $tenant->name,
                'legal_name' => $normalized['company_legal_name'] ?: $tenant->legal_name,
            ])->save();
        });
    }

    public function defaultsForTenant(TenantAccount $tenant): array
    {
        return [
            'company_display_name' => [
                'value' => $tenant->name ?: ($tenant->legal_name ?: 'Prodelya'),
                'type' => 'string',
                'description' => self::SETTINGS['company_display_name']['description'],
            ],
            'company_legal_name' => [
                'value' => $tenant->legal_name ?: '',
                'type' => 'string',
                'description' => self::SETTINGS['company_legal_name']['description'],
            ],
            'company_country' => [
                'value' => 'Türkiye',
                'type' => 'string',
                'description' => self::SETTINGS['company_country']['description'],
            ],
        ];
    }

    private function buildFullAddress(
        ?string $address,
        ?string $district,
        ?string $city,
        ?string $postalCode,
        ?string $country
    ): ?string {
        $segments = array_filter([
            $address,
            trim(collect([$district, $city])->filter()->implode(' / ')),
            trim(collect([$postalCode, $country])->filter()->implode(' ')),
        ]);

        return $segments === [] ? null : implode(', ', $segments);
    }

    private function normalizeWebsite(?string $website): ?string
    {
        $value = $this->cleanValue($website);

        if ($value === null) {
            return null;
        }

        if (! Str::startsWith(Str::lower($value), ['http://', 'https://'])) {
            $value = 'https://' . $value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }

    private function cleanValue(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
