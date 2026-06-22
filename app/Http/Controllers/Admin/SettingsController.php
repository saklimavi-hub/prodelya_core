<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantAccount;
use App\Services\ModuleFeatureCatalogService;
use App\Services\TenantAccessService;
use App\Services\TenantCompanyProfileService;
use App\Services\TenantResolver;
use App\Services\TenantSubscriptionStatusService;
use App\Services\TenantUsageService;
use App\Services\WorkFolderPathService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected WorkFolderPathService $workFolderPathService,
        protected TenantSubscriptionStatusService $subscriptionStatusService,
        protected TenantAccessService $tenantAccessService,
        protected TenantUsageService $tenantUsageService,
        protected ModuleFeatureCatalogService $catalogService,
        protected TenantCompanyProfileService $tenantCompanyProfileService,
    ) {}

    /**
     * Show tenant settings landing page.
     */
    public function index(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);
        $settings = $tenant->settings()->pluck('value', 'key')->toArray();
        $workFolderRootName = $this->workFolderPathService->normalizeSegment(
            (string) ($settings['work_folder_root_name'] ?? WorkFolderPathService::BASE_FOLDER),
            32,
            WorkFolderPathService::BASE_FOLDER
        );

        $accessSummary = $this->tenantAccessService->effectiveAccessSummary($tenant);

        return view('admin.settings.index', [
            'tenant' => $tenant,
            'subscription' => $this->subscriptionStatusService->getStatus($tenant),
            'usageSnapshot' => array_values($accessSummary['usage'] ?? []),
            'usageWarnings' => $accessSummary['warnings'] ?? [],
            'moduleSummary' => $this->buildModuleSummary($accessSummary['modules'] ?? []),
            'featureSummary' => $this->buildFeatureSummary($accessSummary['features'] ?? []),
            'settingsSections' => $this->buildSettingsSections($tenant),
            'portalSummary' => $this->buildPortalSummary($tenant, $accessSummary['features'] ?? []),
            'packageLabel' => $this->resolvePackageLabel($tenant),
            'workFolderRootName' => $workFolderRootName,
            'workFolderPreviewPath' => $workFolderRootName . ' / ABC-INSAAT-AS / SP-2026-0008 / 01-AK-1020-KIRMIZI',
            'tenantReadiness' => $this->buildTenantReadiness($tenant, $settings),
        ]);
    }

    public function update(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $validated = $request->validate([
            'work_folder_root_name' => ['nullable', 'string', 'max:100'],
        ]);

        $normalizedRootName = $this->workFolderPathService->normalizeSegment(
            (string) ($validated['work_folder_root_name'] ?? ''),
            32,
            WorkFolderPathService::BASE_FOLDER
        );

        $tenant->settings()->updateOrCreate(
            ['key' => 'work_folder_root_name'],
            [
                'value' => $normalizedRootName,
                'type' => 'string',
                'description' => 'Sipariş ve İş Formu çalışma klasörleri için kök klasör adı',
                'is_public' => false,
            ]
        );

        return redirect()
            ->route('admin.settings')
            ->with('success', 'Çalışma klasörü ayarı kaydedildi.');
    }

    public function editCompanyProfile(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        return view('admin.settings.company-profile', [
            'tenant' => $tenant,
            'profile' => $this->tenantCompanyProfileService->getProfile($tenant),
        ]);
    }

    public function updateCompanyProfile(Request $request)
    {
        $tenant = $this->tenantResolver->getCurrentTenant($request);

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_office' => ['nullable', 'string', 'max:100'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                $website = trim((string) ($value ?? ''));

                if ($website === '') {
                    return;
                }

                if (! Str::startsWith(Str::lower($website), ['http://', 'https://'])) {
                    $website = 'https://' . $website;
                }

                if (! filter_var($website, FILTER_VALIDATE_URL)) {
                    $fail('Web sitesi adresi geçerli değil.');
                }
            }],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:50'],
        ], [
            'display_name.required' => 'Görünen firma adı zorunludur.',
            'email.email' => 'Geçerli bir e-posta adresi girin.',
        ]);

        $this->tenantCompanyProfileService->updateProfile($tenant, $validated);

        return redirect()
            ->route('admin.settings.company-profile.edit')
            ->with('success', 'Firma bilgileri kaydedildi.');
    }

    private function buildSettingsSections(TenantAccount $tenant): array
    {
        return [
            [
                'title' => 'Firma ve Genel Bilgiler',
                'description' => 'Şirket kimliği, temel dil ve sistem ayarlarını burada bulursunuz.',
                'items' => [
                    $this->settingsItem($tenant, 'Firma Bilgileri', 'Firma adı, ticari unvan ve panelde görünen temel bilgiler.', 'admin.settings.company-profile.edit', null, null, 'Bu sayfada'),
                    $this->settingsItem($tenant, 'Dil ve Para Birimi', 'Varsayılan dil, para birimi ve zaman dilimi tercihleri.', 'admin.settings', null, null, 'Bu sayfada'),
                    $this->settingsItem($tenant, 'Temel Sistem Ayarları', 'Çalışma klasörü ve güvenli gösterim ayarları.', 'admin.settings', null, null, 'Bu sayfada'),
                    $this->settingsItem($tenant, 'Özel Domain', 'Panel veya portal için özel domain kullanımı.', null, 'custom_domain', null, 'Yakında'),
                ],
            ],
            [
                'title' => 'Operasyon Ayarları',
                'description' => 'Günlük iş akışınızı etkileyen üretim ve operasyon ayarları.',
                'items' => [
                    $this->settingsItem($tenant, 'Baskı Ayarları', 'Baskı türleri, setup gereksinimleri ve varsayılan operasyon kuralları.', 'admin.settings.print-settings.index', 'print_settings'),
                    $this->settingsItem($tenant, 'Grafik ve Üretim', 'Grafik ve üretim ekiplerinin kullandığı operasyon omurgası.', null, 'graphics', null, 'Aktif modül'),
                    $this->settingsItem($tenant, 'Teslimat ve Finans', 'Teslimat ile finans akışları bu tenantta aktif olarak çalışır.', null, 'delivery', null, 'Aktif modül'),
                ],
            ],
            [
                'title' => 'Müşteri Portalı',
                'description' => 'Müşterinin hangi ekranlara erişebileceğini bu bölümden takip edin.',
                'items' => [
                    $this->settingsItem($tenant, 'Portal Durumu', 'Portal erişimi bu tenant için açık mı kapalı mı hızlıca görün.', null, 'customer_portal', null, 'Özetten takip edin'),
                    $this->settingsItem($tenant, 'Müşteri Girişi', 'Müşterinin portala giriş yapıp yapamayacağını gösterir.', null, 'customer_portal', 'customer_login', 'Özetten takip edin'),
                    $this->settingsItem($tenant, 'Teklif, Sipariş ve Dosya Görünümü', 'Portalda teklif, sipariş ve müşteri dosyalarının erişim durumu.', null, 'customer_portal', 'portal_quotes', 'Özetten takip edin'),
                ],
            ],
            [
                'title' => 'Bildirimler',
                'description' => 'Mail, WhatsApp, şablon ve bildirim geçmişini tek yerden yönetin.',
                'items' => [
                    $this->settingsItem($tenant, 'Mail Gönderimi', 'Giden e-posta ayarları, gönderen bilgileri ve test maili.', 'admin.settings.notifications.smtp', 'notification_center', 'smtp_settings'),
                    $this->settingsItem($tenant, 'WhatsApp Hazır Mesaj', 'Güvenli hazır mesaj ve link oluşturma ekranı.', 'admin.settings.notifications.whatsapp', 'notification_center', 'whatsapp_links'),
                    $this->settingsItem($tenant, 'Bildirim Şablonları', 'Olay bazlı bildirim şablonları.', 'admin.notifications.templates.index', 'notification_center', 'notification_templates'),
                    $this->settingsItem($tenant, 'Bildirim Geçmişi', 'Bildirim logları ve sade operasyon geçmişi.', 'admin.notifications.logs.index', 'notification_center', 'notification_logs'),
                ],
            ],
            [
                'title' => 'Paket ve Limitler',
                'description' => 'Paketinizde neler açık, hangi limitler doluyor, tek bakışta görün.',
                'items' => [
                    $this->settingsItem($tenant, 'Mevcut Paket', 'Kullandığınız paket ve erişim durumu.', 'admin.settings', null, null, 'Bu sayfada'),
                    $this->settingsItem($tenant, 'Aktif Modüller', 'Şu an kullanabildiğiniz modüller ve ek özellikler.', 'admin.settings', null, null, 'Bu sayfada'),
                    $this->settingsItem($tenant, 'Kullanım Limitleri', 'Kullanıcı, sipariş ve depolama limitleriniz.', 'admin.settings', null, null, 'Bu sayfada'),
                ],
            ],
            [
                'title' => 'Kullanıcılar ve Yetkiler',
                'description' => 'Ekibin erişim düzenini ve kullanıcı haklarını takip edin.',
                'items' => [
                    $this->settingsItem($tenant, 'Kullanıcı Yönetimi', 'Ekip kullanıcıları ve erişim yetkileri.', 'admin.users.index', 'user_management', null, 'Yakında'),
                    $this->settingsItem($tenant, 'Rol ve Yetkiler', 'Roller ve yetki kurgusu bu tenantta çekirdek olarak aktif.', null, 'user_management', null, 'Aktif modül'),
                    $this->settingsItem($tenant, 'API Erişimi', 'API token ve entegrasyon erişimleri.', null, 'api_access', null, 'Yakında'),
                ],
            ],
        ];
    }

    private function settingsItem(
        TenantAccount $tenant,
        string $title,
        string $description,
        ?string $routeName,
        ?string $moduleKey = null,
        ?string $featureKey = null,
        ?string $fallbackBadge = null
    ): array {
        $routeAvailable = $routeName !== null && Route::has($routeName);
        $moduleEnabled = $moduleKey === null || $this->tenantAccessService->canAccessModule($tenant, $moduleKey);
        $featureEnabled = $featureKey === null || $this->tenantAccessService->canAccessFeature($tenant, $featureKey, $moduleKey);
        $available = $routeAvailable && $moduleEnabled && $featureEnabled;
        $inPackage = $moduleEnabled && $featureEnabled;

        return [
            'title' => $title,
            'description' => $description,
            'route' => $available ? route($routeName) : null,
            'available' => $available,
            'help' => 'Bu ayar ne işe yarar? ' . $description,
            'badge' => $available ? 'Aktif' : ($fallbackBadge ?? ($inPackage ? 'Yakında' : 'Pakette yok')),
            'badge_tone' => $available ? 'green' : ($inPackage ? 'gray' : 'amber'),
        ];
    }

    private function buildModuleSummary(array $modules): array
    {
        $summary = [
            'core' => [],
            'enabled_optional' => [],
            'disabled_optional' => [],
        ];

        foreach ($modules as $key => $status) {
            $module = $this->catalogService->getModule($key);

            if (!$module || in_array($module['status'] ?? 'passive', ['planned', 'passive', 'deprecated'], true)) {
                continue;
            }

            $item = [
                'label' => $module['label'] ?? Str::headline($key),
                'description' => $module['description'] ?? null,
                'status_label' => (bool) ($status['enabled'] ?? false) ? 'Aktif' : 'Pakette yok',
                'status_tone' => (bool) ($status['enabled'] ?? false) ? 'blue' : 'gray',
            ];

            if ((bool) ($module['is_core'] ?? false)) {
                $summary['core'][] = $item;
            } elseif ((bool) ($status['enabled'] ?? false)) {
                $summary['enabled_optional'][] = $item;
            } else {
                $summary['disabled_optional'][] = $item;
            }
        }

        return $summary;
    }

    private function buildFeatureSummary(array $features): array
    {
        $enabled = 0;
        $disabled = 0;

        foreach ($features as $status) {
            if (($status['enabled'] ?? false) === true) {
                $enabled++;
            } else {
                $disabled++;
            }
        }

        return [
            'enabled_count' => $enabled,
            'disabled_count' => $disabled,
        ];
    }

    private function buildPortalSummary(TenantAccount $tenant, array $features): array
    {
        $moduleEnabled = $this->tenantAccessService->canAccessModule($tenant, 'customer_portal');
        $featureMap = Collection::make($features)->mapWithKeys(fn (array $item, string $key) => [$key => (bool) ($item['enabled'] ?? false)]);

        return [
            [
                'label' => 'Portal Durumu',
                'value' => $moduleEnabled ? 'Açık' : 'Kapalı',
                'tone' => $moduleEnabled ? 'green' : 'gray',
            ],
            [
                'label' => 'Müşteri Girişi',
                'value' => $featureMap->get('customer_login') ? 'Aktif' : ($moduleEnabled ? 'Kapalı' : 'Pakette yok'),
                'tone' => $featureMap->get('customer_login') ? 'green' : ($moduleEnabled ? 'amber' : 'gray'),
            ],
            [
                'label' => 'Portal Teklifleri',
                'value' => $featureMap->get('portal_quotes') ? 'Aktif' : ($moduleEnabled ? 'Kapalı' : 'Pakette yok'),
                'tone' => $featureMap->get('portal_quotes') ? 'green' : ($moduleEnabled ? 'amber' : 'gray'),
            ],
            [
                'label' => 'Portal Siparişleri',
                'value' => $featureMap->get('portal_orders') ? 'Aktif' : ($moduleEnabled ? 'Kapalı' : 'Pakette yok'),
                'tone' => $featureMap->get('portal_orders') ? 'green' : ($moduleEnabled ? 'amber' : 'gray'),
            ],
        ];
    }

    private function resolvePackageLabel(TenantAccount $tenant): string
    {
        $packageKey = trim((string) ($tenant->package_key ?? ''));

        if ($packageKey === '') {
            return 'Core';
        }

        return (string) Str::of($packageKey)
            ->replace(['_', '-'], ' ')
            ->headline();
    }

    private function buildTenantReadiness(TenantAccount $tenant, array $settings): array
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = trim($host) !== '' ? $host : 'prodelya_core.test';
        $panelHost = filled($tenant->panel_subdomain) ? $tenant->panel_subdomain . '.' . $host : null;

        $companyProfile = $this->tenantCompanyProfileService->getProfile($tenant);

        return [
            'company_display_name' => $companyProfile['display_name'],
            'company_legal_name' => $companyProfile['legal_name'],
            'company_full_address' => $companyProfile['full_address'],
            'company_phone' => $companyProfile['phone'],
            'company_email' => $companyProfile['email'],
            'company_website' => $companyProfile['website'],
            'default_locale' => $tenant->default_locale,
            'default_currency' => $tenant->default_currency,
            'timezone' => $tenant->timezone,
            'number_format_locale' => $tenant->number_format_locale,
            'storage_disk' => (string) ($settings['storage_disk'] ?? 'local'),
            'panel_url' => $panelHost ? 'http://' . $panelHost . '/admin' : null,
            'panel_host' => $panelHost,
            'has_first_company' => $tenant->companies()->exists(),
            'has_first_current_account' => $tenant->currentAccounts()->exists(),
            'smtp_ready' => filled($settings['smtp_from_email'] ?? null) || filled($settings['smtp_host'] ?? null),
            'whatsapp_ready' => filled($settings['whatsapp_test_phone'] ?? null)
                || filled($settings['whatsapp_default_signature'] ?? null),
            'legacy_import_done' => false,
        ];
    }
}
