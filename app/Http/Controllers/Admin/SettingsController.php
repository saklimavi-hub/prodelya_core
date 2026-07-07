<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantAccount;
use App\Models\TenantPackageUpgradeRequest;
use App\Models\TenantSetting;
use App\Models\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\Notifications\TenantNotificationSettingsService;
use App\Services\ModuleFeatureCatalogService;
use App\Services\TenantAccessService;
use App\Services\TenantCompanyProfileService;
use App\Services\TenantCatalog\TenantCatalogListRowQueryService;
use App\Services\TenantDeliveryTypeService;
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
        protected TenantNotificationSettingsService $tenantNotificationSettingsService,
        protected TenantCatalogListRowQueryService $tenantCatalogListRowQueryService,
        protected TenantDeliveryTypeService $tenantDeliveryTypeService,
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
        $companyProfile = $this->tenantCompanyProfileService->getProfile($tenant);
        $tenantReadiness = $this->buildTenantReadiness($tenant, $settings);
        $activeTab = $this->resolveActiveTab((string) $request->query('tab', 'company-profile'));
        $notificationSummary = $this->buildNotificationSummary($tenant);
        $catalogSummary = $this->buildCatalogSummary($tenant);
        $readinessChecklist = $this->buildReadinessChecklist($tenantReadiness, $settings, $tenant);
        $packageRequestSummary = $this->buildPackageRequestSummary($tenant);
        $packageOverviewRoute = $this->resolvePackageOverviewRoute($tenant);
        $moduleCategories = $this->buildTenantModuleCategories(
            $accessSummary['modules'] ?? [],
            $accessSummary['features'] ?? []
        );
        $domainSummary = $this->buildDomainSummary($tenant);
        $deliveryTypeSummary = $this->buildDeliveryTypeSummary($tenant);

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
            'tenantReadiness' => $tenantReadiness,
            'companyProfile' => $companyProfile,
            'domainSummary' => $domainSummary,
            'notificationSummary' => $notificationSummary,
            'catalogSummary' => $catalogSummary,
            'readinessChecklist' => $readinessChecklist,
            'packageRequestSummary' => $packageRequestSummary,
            'packageOverviewRoute' => $packageOverviewRoute,
            'activeSettingsTab' => $activeTab,
            'settingsTabs' => $this->settingsTabs(),
            'settingsOverview' => $this->buildSettingsOverview(
                $tenant,
                $readinessChecklist,
                $notificationSummary,
                $catalogSummary,
                $packageRequestSummary,
                $packageOverviewRoute
            ),
            'companyProfileCards' => $this->buildCompanyProfileCards($tenant, $companyProfile),
            'userRoleSummary' => $this->buildUserRoleSummary($tenant),
            'moduleCategories' => $moduleCategories,
            'storageSummary' => $this->buildStorageSummary($workFolderRootName),
            'deliveryTypeSummary' => $deliveryTypeSummary,
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
        $profile = $this->tenantCompanyProfileService->getProfile($tenant);

        return view('admin.settings.company-profile', [
            'tenant' => $tenant,
            'profile' => $profile,
            'profileSummary' => $this->buildCompanyProfileEditSummary($tenant, $profile),
        ]);
    }

    private function settingsTabs(): array
    {
        return [
            ['key' => 'company-profile', 'label' => 'Firma Profili', 'description' => 'Firma kimliği ve temel görünür bilgiler'],
            ['key' => 'panel-portal', 'label' => 'Panel ve Portal', 'description' => 'Panel adresi, müşteri portalı ve paylaşım bağlantıları'],
            ['key' => 'notifications', 'label' => 'Bildirimler', 'description' => 'SMTP, WhatsApp ve bildirim şablonları'],
            ['key' => 'package-limits', 'label' => 'Paket ve Limitler', 'description' => 'Paket durumu, modüller ve kullanım sınırları'],
            ['key' => 'users-roles', 'label' => 'Kullanıcılar ve Roller', 'description' => 'Ekip kullanıcıları ve rol yetkileri'],
            ['key' => 'catalog-product-hub', 'label' => 'Katalog ve Product Hub', 'description' => 'Katalog görünürlüğü ve ürün seçim hazırlığı'],
            ['key' => 'request-center', 'label' => 'Talep Merkezi', 'description' => 'Paket, modül ve hizmet talepleri'],
            ['key' => 'file-storage', 'label' => 'Dosya ve Depolama', 'description' => 'Çalışma klasörü ve planlanan harici depolama'],
        ];
    }

    private function resolveActiveTab(string $requested): string
    {
        $allowed = collect($this->settingsTabs())->pluck('key')->all();

        return in_array($requested, $allowed, true) ? $requested : 'company-profile';
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
        $currentUser = auth()->user();
        $canManage = $currentUser && $currentUser->hasPermissionInTenant('manage_users', $tenant->id);
        $packageOverviewRoute = $canManage && Route::has('admin.my-package.index')
            ? 'admin.my-package.index'
            : 'admin.settings';

        $sections = [
            [
                'title' => 'Firma Bilgileri',
                'description' => 'Abone Firma kimliği, temel dil ve genel kurulum bilgilerini burada toparlayın.',
                'items' => [
                    $this->settingsItem($tenant, 'Firma Bilgileri', 'Firma adı, ticari unvan ve panelde görünen temel bilgiler.', 'admin.settings.company-profile.edit', null, null, 'Hazır'),
                    $this->settingsItem($tenant, 'Dil ve Para Birimi', 'Varsayılan dil, para birimi ve zaman dilimi tercihleri.', 'admin.settings', null, null, 'Hazır'),
                    $this->settingsItem($tenant, 'Temel Sistem Ayarları', 'Çalışma klasörü ve güvenli gösterim ayarları.', 'admin.settings', null, null, 'Hazır'),
                    $this->settingsItem($tenant, 'Özel Domain', 'Panel veya portal için özel domain kullanımı.', null, 'custom_domain', null, 'Sonraki Faz'),
                ],
            ],
            [
                'title' => 'Baskı / Belge Ayarları',
                'description' => 'Günlük iş akışınızı etkileyen baskı, belge ve operasyon ayarları.',
                'items' => [
                    $this->settingsItem($tenant, 'Baskı Ayarları', 'Baskı türleri, setup gereksinimleri ve varsayılan operasyon kuralları.', 'admin.settings.print-settings.index', 'print_settings'),
                    $this->settingsItem($tenant, 'Grafik ve Üretim', 'Grafik ve üretim ekiplerinin kullandığı operasyon omurgası.', null, 'graphics', null, 'Hazır'),
                    $this->settingsItem($tenant, 'Teslimat ve Finans', 'Teslimat ile finans akışları bu abone firmada aktif olarak çalışır.', null, 'delivery', null, 'Hazır'),
                ],
            ],
            [
                'title' => 'Panel ve Portal',
                'description' => 'Panel adresi, müşteri portalı ve görünür giriş yüzeylerini bu bölümden takip edin.',
                'items' => [
                    $this->settingsItem($tenant, 'Portal Durumu', 'Portal erişimi bu abone firma için açık mı kapalı mı hızlıca görün.', null, 'customer_portal', null, 'Kontrol Gerekir'),
                    $this->settingsItem($tenant, 'Müşteri Girişi', 'Müşterinin portala giriş yapıp yapamayacağını gösterir.', null, 'customer_portal', 'customer_login', 'Kontrol Gerekir'),
                    $this->settingsItem($tenant, 'Teklif, Sipariş ve Dosya Görünümü', 'Portalda teklif, sipariş ve müşteri dosyalarının erişim durumu.', null, 'customer_portal', 'portal_quotes', 'Kontrol Gerekir'),
                ],
            ],
            [
                'title' => 'Bildirim Ayarları',
                'description' => 'Mail, WhatsApp, şablon ve bildirim geçmişini tek yerden yönetin.',
                'items' => [
                    $this->settingsItem($tenant, 'Mail Gönderimi', 'Giden e-posta ayarları, gönderen bilgileri ve test maili.', 'admin.settings.notifications.smtp', 'notification_center', 'smtp_settings'),
                    $this->settingsItem($tenant, 'WhatsApp Hazır Mesaj', 'Güvenli hazır mesaj ve link oluşturma ekranı.', 'admin.settings.notifications.whatsapp', 'notification_center', 'whatsapp_links'),
                    $this->settingsItem($tenant, 'Bildirim Şablonları', 'Olay bazlı bildirim şablonları.', 'admin.notifications.templates.index', 'notification_center', 'notification_templates'),
                    $this->settingsItem($tenant, 'Bildirim Geçmişi', 'Bildirim logları ve sade operasyon geçmişi.', 'admin.notifications.logs.index', 'notification_center', 'notification_logs'),
                ],
            ],
            [
                'title' => 'Paket ve Modüller',
                'description' => 'Paketinizde neler açık, hangi limitler doluyor, tek bakışta görün.',
                'items' => [
                    $this->settingsItem($tenant, 'Mevcut Paket', 'Kullandığınız paket ve erişim durumu.', $packageOverviewRoute, null, null, 'Hazır'),
                    $this->settingsItem($tenant, 'Aktif Modüller', 'Şu an kullanabildiğiniz modüller ve ek özellikler.', $packageOverviewRoute, null, null, 'Hazır'),
                    $this->settingsItem($tenant, 'Kullanım Limitleri', 'Kullanıcı, sipariş ve depolama limitleriniz.', $packageOverviewRoute, null, null, 'Hazır'),
                ],
            ],
            [
                'title' => 'Kullanıcılar ve Roller',
                'description' => 'Ekibin erişim düzenini ve kullanıcı yetkilerini takip edin.',
                'items' => [
                    $this->settingsItem($tenant, 'Kullanıcı Yönetimi', 'Ekip kullanıcıları ve erişim yetkileri.', 'admin.users.index', 'user_management', 'tenant_users'),
                    $this->settingsItem($tenant, 'Rol ve Yetkiler', 'Roller ve yetki kurgusu bu abone firmada çekirdek olarak aktif.', null, 'user_management', null, 'Hazır'),
                    $this->settingsItem($tenant, 'API Erişimi', 'API token ve entegrasyon erişimleri.', null, 'api_access', null, 'Sonraki Faz'),
                ],
            ],
        ];

        return array_map(fn (array $section) => $this->decorateSettingsSection($section), $sections);
    }

    private function buildDomainSummary(TenantAccount $tenant): array
    {
        $host = $this->panelBaseHost();
        $scheme = $this->preferredScheme();
        $panelUrl = filled($tenant->panel_subdomain) ? $scheme . '://' . $tenant->panel_subdomain . '.' . $host . '/admin' : null;
        $portalUrl = filled($tenant->portal_domain)
            ? $scheme . '://' . $tenant->portal_domain . '/musteri-giris'
            : (filled($tenant->panel_subdomain) ? $scheme . '://' . $tenant->panel_subdomain . '.' . $host . '/musteri-giris' : null);

        return [
            'panel_subdomain' => $tenant->panel_subdomain ?: 'Tanımlı değil',
            'panel_url' => $panelUrl,
            'custom_domain' => $tenant->custom_domain ?: 'Tanımlı değil',
            'portal_domain' => $tenant->portal_domain ?: 'Tanımlı değil',
            'portal_url' => $portalUrl,
            'domain_note' => 'DNS ve SSL doğrulama otomasyonu sonraki fazda ele alınacaktır.',
        ];
    }

    private function buildNotificationSummary(TenantAccount $tenant): array
    {
        $summary = $this->tenantNotificationSettingsService->readinessSummary($tenant);
        $notificationModuleEnabled = $this->tenantAccessService->canAccessModule($tenant, 'notification_center');
        $smtpAvailable = Route::has('admin.settings.notifications.smtp')
            && $notificationModuleEnabled
            && $this->tenantAccessService->canAccessFeature($tenant, 'smtp_settings', 'notification_center');
        $whatsappAvailable = Route::has('admin.settings.notifications.whatsapp')
            && $notificationModuleEnabled
            && $this->tenantAccessService->canAccessFeature($tenant, 'whatsapp_links', 'notification_center');
        $templatesAvailable = Route::has('admin.notifications.templates.index')
            && $notificationModuleEnabled
            && $this->tenantAccessService->canAccessFeature($tenant, 'notification_templates', 'notification_center');
        $logsAvailable = Route::has('admin.notifications.logs.index')
            && $notificationModuleEnabled
            && $this->tenantAccessService->canAccessFeature($tenant, 'notification_logs', 'notification_center');

        $summary['smtp']['route'] = $smtpAvailable ? route('admin.settings.notifications.smtp') : null;
        $summary['whatsapp']['route'] = $whatsappAvailable ? route('admin.settings.notifications.whatsapp') : null;
        $summary['templates']['route'] = $templatesAvailable ? route('admin.notifications.templates.index') : null;
        $summary['logs']['route'] = $logsAvailable ? route('admin.notifications.logs.index') : null;
        $summary['templates']['status_label'] = $notificationModuleEnabled
            ? ($summary['templates']['status_label'] ?? 'Kontrol Edilmeli')
            : 'Pakette Yok';
        $summary['logs']['status_label'] = $notificationModuleEnabled
            ? ($summary['logs']['status_label'] ?? 'Veri yok')
            : 'Pakette Yok';
        $summary['readiness_note'] = 'Bu fazda gerçek gönderim yerine güvenli önizleme ve log davranışı kullanılır. Test mail ayrı olarak mevcut güvenli akış üzerinden denenebilir.';

        return $summary;
    }

    private function buildCatalogSummary(TenantAccount $tenant): array
    {
        $summary = $this->tenantCatalogListRowQueryService->summary($tenant);
        $hasSupplierAccess = $tenant->supplierAccesses()->active()->exists();
        $hasCatalogProducts = ($summary['visible_catalog_rows'] ?? 0) > 0;
        $hasProductHub = $this->tenantAccessService->canAccessModule($tenant, 'product_data_hub');
        $hasQuoteRows = ($summary['quote_visible_rows'] ?? 0) > 0;
        $attentionRows = (int) ($summary['attention_rows'] ?? 0);
        $catalogRoute = $this->tenantAccessService->canAccessModule($tenant, 'advanced_catalog') && Route::has('admin.catalog.index')
            ? route('admin.catalog.index')
            : null;
        $quoteRoute = $this->tenantAccessService->canAccessModule($tenant, 'order_flow') && Route::has('admin.promotion-quotes.create')
            ? route('admin.promotion-quotes.create')
            : null;

        return [
            'supplier_access' => $hasSupplierAccess ? 'Hazır' : 'Eksik',
            'catalog_products' => $hasCatalogProducts ? 'Hazır' : 'Kontrol Gerekir',
            'product_hub' => $hasProductHub ? 'Bilgi' : 'Sonraki Faz',
            'visible_catalog_rows' => (int) ($summary['visible_catalog_rows'] ?? 0),
            'quote_visible_rows' => (int) ($summary['quote_visible_rows'] ?? 0),
            'attention_rows' => $attentionRows,
            'supplier_access_count' => $tenant->supplierAccesses()->active()->count(),
            'status_label' => $hasQuoteRows ? 'Hazır' : ($hasCatalogProducts ? 'Kontrol Gerekir' : 'Sonraki Faz'),
            'catalog_route' => $catalogRoute,
            'quote_route' => $quoteRoute,
            'guidance_note' => 'Katalog erişimi Prodelya tarafından yönetilir. Yeni tedarikçi veya modül için Talep Merkezi’nden talep açabilirsiniz.',
            'technical_note' => 'Product Data Hub teknik yönetimi bu ekranda gösterilmez.',
        ];
    }

    private function buildPackageRequestSummary(TenantAccount $tenant): array
    {
        $query = TenantPackageUpgradeRequest::query()
            ->where('tenant_account_id', $tenant->id);

        $requests = (clone $query)
            ->with(['requestedPackage'])
            ->latest()
            ->take(5)
            ->get();

        $latest = $requests->first();
        $currentUser = auth()->user();
        $canManage = $currentUser && $currentUser->hasPermissionInTenant('manage_users', $tenant->id);

        return [
            'latest' => $latest,
            'open_count' => (clone $query)->whereIn('status', [
                TenantPackageUpgradeRequest::STATUS_NEW,
                TenantPackageUpgradeRequest::STATUS_APPROVED,
            ])->count(),
            'route' => $canManage && Route::has('admin.upgrade-requests.index') ? route('admin.upgrade-requests.index') : null,
            'status_labels' => TenantPackageUpgradeRequest::statusOptions(),
        ];
    }

    private function buildReadinessChecklist(array $tenantReadiness, array $settings, TenantAccount $tenant): array
    {
        $tenantOwnerRoleId = Role::query()->where('key', 'tenant_owner')->where('is_active', true)->value('id');
        $hasOwner = $tenantOwnerRoleId
            ? UserRole::query()->where('tenant_account_id', $tenant->id)->where('role_id', $tenantOwnerRoleId)->exists()
            : false;

        return [
            ['label' => 'Firma Bilgileri', 'status' => filled($tenantReadiness['company_display_name']) && (filled($tenantReadiness['company_email']) || filled($tenantReadiness['company_phone'])) ? 'Hazır' : 'Eksik'],
            ['label' => 'Panel Adresi', 'status' => filled($tenantReadiness['panel_url']) ? 'Hazır' : 'Eksik'],
            ['label' => 'Panel Yetkilisi', 'status' => $hasOwner ? 'Hazır' : 'Eksik'],
            ['label' => 'SMTP', 'status' => $tenantReadiness['smtp_ready'] ? 'Hazır' : 'Eksik'],
            ['label' => 'WhatsApp', 'status' => $tenantReadiness['whatsapp_ready'] ? 'Hazır' : 'Eksik'],
            ['label' => 'İlk Firma / Cari', 'status' => $tenantReadiness['has_first_company'] && $tenantReadiness['has_first_current_account'] ? 'Hazır' : 'Kontrol Gerekir'],
            ['label' => 'Katalog', 'status' => $tenant->catalogProducts()->exists() ? 'Hazır' : 'Sonraki Faz'],
            ['label' => 'Portal', 'status' => (bool) TenantSetting::getValue($tenant->id, 'portal_enabled', false) ? 'Hazır' : 'Sonraki Faz'],
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
            'badge' => $available ? 'Hazır' : ($fallbackBadge ?? ($inPackage ? 'Sonraki Faz' : 'Pakette Yok')),
            'badge_tone' => $available ? 'green' : ($inPackage ? 'gray' : 'amber'),
        ];
    }

    private function decorateSettingsSection(array $section): array
    {
        $items = collect($section['items'] ?? []);
        $primaryAction = $items->first(fn (array $item) => ($item['available'] ?? false) && filled($item['route']));
        $statuses = $items->pluck('badge')->all();

        $section['status_label'] = in_array('Eksik', $statuses, true) || in_array('Kontrol Gerekir', $statuses, true)
            ? 'Kontrol Gerekir'
            : (in_array('Sonraki Faz', $statuses, true) ? 'Sonraki Faz' : (in_array('Pakette Yok', $statuses, true) ? 'Pakette Yok' : 'Hazır'));
        $section['status_tone'] = match ($section['status_label']) {
            'Hazır' => 'green',
            'Pakette Yok' => 'gray',
            'Sonraki Faz' => 'gray',
            default => 'amber',
        };
        $section['missing_summary'] = $items
            ->filter(fn (array $item) => ($item['badge'] ?? 'Hazır') !== 'Hazır')
            ->pluck('title')
            ->take(2)
            ->implode(' · ');
        $section['primary_action'] = $primaryAction
            ? ['label' => 'Yönet', 'route' => $primaryAction['route']]
            : null;

        return $section;
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

    private function buildSettingsOverview(
        TenantAccount $tenant,
        array $readinessChecklist,
        array $notificationSummary,
        array $catalogSummary,
        array $packageRequestSummary,
        ?string $packageOverviewRoute
    ): array {
        $readyCount = collect($readinessChecklist)->where('status', 'Hazır')->count();
        $attentionCollection = collect($readinessChecklist)
            ->filter(fn (array $item) => in_array($item['status'], ['Eksik', 'Kontrol Gerekir'], true))
            ->values();
        $attentionItems = $attentionCollection
            ->pluck('label')
            ->take(3)
            ->values()
            ->all();
        $laterCount = collect($readinessChecklist)->where('status', 'Sonraki Faz')->count();
        $packageLabel = $this->resolvePackageLabel($tenant);

        return [
            'package_label' => $packageLabel,
            'ready_count' => $readyCount,
            'attention_count' => $attentionCollection->count(),
            'later_count' => $laterCount,
            'total_count' => count($readinessChecklist),
            'progress_percent' => count($readinessChecklist) > 0 ? (int) round(($readyCount / count($readinessChecklist)) * 100) : 0,
            'attention_items' => $attentionItems,
            'status_badges' => array_values(array_filter([
                ['label' => $packageLabel, 'tone' => 'blue'],
                ['label' => $catalogSummary['status_label'] ?? 'Bilgi', 'tone' => ($catalogSummary['status_label'] ?? '') === 'Hazır' ? 'green' : 'amber'],
                ['label' => $notificationSummary['smtp']['status_label'] ?? 'Bilgi', 'tone' => ($notificationSummary['smtp']['is_ready'] ?? false) ? 'green' : 'gray'],
                $packageRequestSummary['open_count'] > 0 ? ['label' => $packageRequestSummary['open_count'] . ' açık talep', 'tone' => 'amber'] : null,
            ])),
            'focus_actions' => $this->buildSettingsFocusActions(
                $readinessChecklist,
                $catalogSummary,
                $notificationSummary
            ),
            'quick_links' => array_values(array_filter([
                $this->quickLink('Firma Bilgileri', Route::has('admin.settings.company-profile.edit') ? route('admin.settings.company-profile.edit') : null),
                $this->quickLink('Teslimat Tipleri', Route::has('admin.settings.delivery-types.index') ? route('admin.settings.delivery-types.index') : null),
                $this->quickLink('Paket ve Kullanım', $packageOverviewRoute),
                $this->quickLink('Katalog', Route::has('admin.catalog.index') ? route('admin.catalog.index') : null),
                $this->quickLink('Kullanıcılar', Route::has('admin.users.index') ? route('admin.users.index') : null),
                $this->quickLink('Talep Merkezi', $packageRequestSummary['route'] ?? null),
            ])),
            'quick_tabs' => [
                ['label' => 'Firma Profili', 'tab' => 'company-profile'],
                ['label' => 'Panel ve Portal', 'tab' => 'panel-portal'],
                ['label' => 'Bildirimler', 'tab' => 'notifications'],
                ['label' => 'Paket ve Limitler', 'tab' => 'package-limits'],
                ['label' => 'Katalog', 'tab' => 'catalog-product-hub'],
                ['label' => 'Talep Merkezi', 'tab' => 'request-center'],
            ],
            'latest_signal' => $notificationSummary['logs']['count'] > 0
                ? 'Bildirim geçmişi'
                : (($catalogSummary['attention_rows'] ?? 0) > 0 ? 'Katalog kontrolü' : 'Kurulum özeti'),
        ];
    }

    private function buildSettingsFocusActions(
        array $readinessChecklist,
        array $catalogSummary,
        array $notificationSummary
    ): array {
        $items = [];
        $statusByLabel = collect($readinessChecklist)->mapWithKeys(fn (array $item) => [$item['label'] => $item['status']]);

        if (($statusByLabel['Firma Bilgileri'] ?? null) !== 'Hazır') {
            $items[] = [
                'title' => 'Firma bilgileri tamamlanmalı',
                'description' => 'Görünen firma adı, iletişim ve temel profil alanları kontrol edilmeli.',
                'tone' => 'amber',
                'tab' => 'company-profile',
            ];
        }

        if (($statusByLabel['Portal'] ?? null) !== 'Hazır' || ($statusByLabel['Panel Adresi'] ?? null) !== 'Hazır') {
            $items[] = [
                'title' => 'Portal ve panel durumu kontrol edilmeli',
                'description' => 'Müşteri girişi, paylaşım bağlantıları ve panel adresi netleştirilmeli.',
                'tone' => 'amber',
                'tab' => 'panel-portal',
            ];
        }

        if (($statusByLabel['SMTP'] ?? null) !== 'Hazır' || ($statusByLabel['WhatsApp'] ?? null) !== 'Hazır' || (int) ($notificationSummary['logs']['failed_count'] ?? 0) > 0) {
            $items[] = [
                'title' => 'Bildirim yüzeyleri gözden geçirilmeli',
                'description' => 'SMTP, WhatsApp veya başarısız bildirim kayıtları kontrol edilmeli.',
                'tone' => 'amber',
                'tab' => 'notifications',
            ];
        }

        if (($catalogSummary['attention_rows'] ?? 0) > 0 || ($statusByLabel['Katalog'] ?? null) !== 'Hazır') {
            $items[] = [
                'title' => 'Katalog görünürlüğü kontrol edilmeli',
                'description' => 'Ürün/varyant satırları ve teklifte seçilebilir kayıtlar gözden geçirilmeli.',
                'tone' => 'amber',
                'tab' => 'catalog-product-hub',
            ];
        }

        if (($statusByLabel['Panel Yetkilisi'] ?? null) !== 'Hazır') {
            $items[] = [
                'title' => 'Kullanıcı ve rol yapısı tamamlanmalı',
                'description' => 'Panel Yetkilisi ve operasyon rolleri gözden geçirilmeli.',
                'tone' => 'amber',
                'tab' => 'users-roles',
            ];
        }

        if ($items === []) {
            $items[] = [
                'title' => 'Acil eksik görünmüyor',
                'description' => 'Kurulum checklist’inde bugün için kritik bir eksik görünmüyor.',
                'tone' => 'green',
                'tab' => 'company-profile',
            ];
        }

        return array_slice($items, 0, 5);
    }

    private function resolvePackageOverviewRoute(TenantAccount $tenant): ?string
    {
        $currentUser = auth()->user();
        $canManage = $currentUser && $currentUser->hasPermissionInTenant('manage_users', $tenant->id);

        return $canManage && Route::has('admin.my-package.index')
            ? route('admin.my-package.index')
            : null;
    }

    private function buildCompanyProfileCards(TenantAccount $tenant, array $profile): array
    {
        return [
            'identity' => [
                'title' => 'Firma Bilgileri',
                'items' => [
                    ['label' => 'Görünen firma adı', 'value' => $profile['display_name'] ?: '-'],
                    ['label' => 'Yasal unvan', 'value' => $profile['legal_name'] ?: 'Henüz girilmedi'],
                    ['label' => 'Telefon', 'value' => $profile['phone'] ?: 'Henüz girilmedi'],
                    ['label' => 'E-posta', 'value' => $profile['email'] ?: 'Henüz girilmedi'],
                    ['label' => 'Web sitesi', 'value' => $profile['website'] ?: 'Henüz girilmedi'],
                    ['label' => 'Ülke / Şehir', 'value' => collect([$profile['country'] ?? null, $profile['city'] ?? null])->filter()->implode(' / ') ?: 'Henüz girilmedi'],
                ],
                'action_url' => Route::has('admin.settings.company-profile.edit') ? route('admin.settings.company-profile.edit') : null,
                'action_label' => 'Firma Bilgilerini Düzenle',
            ],
            'locale' => [
                'title' => 'Dil, Para ve Zaman',
                'items' => [
                    ['label' => 'Dil', 'value' => $tenant->default_locale ?: '-'],
                    ['label' => 'Para birimi', 'value' => $tenant->default_currency ?: '-'],
                    ['label' => 'Zaman dilimi', 'value' => $tenant->timezone ?: '-'],
                    ['label' => 'Tarih / sayı formatı', 'value' => $tenant->number_format_locale ?: '-'],
                ],
            ],
            'brand' => [
                'title' => 'Belge / Görsel Kimlik Özeti',
                'items' => [
                    ['label' => 'Logo', 'value' => $profile['logo_url'] ? 'Hazır' : 'Logo yükleme sınırlı'],
                    ['label' => 'Belge ana rengi', 'value' => 'Sonraki Faz'],
                    ['label' => 'Belge kısa adı', 'value' => 'Sonraki Faz'],
                ],
                'note' => 'Bu bilgiler teklif PDF’i ve iş formu görünümünde kullanılacaktır.',
            ],
            'note' => 'Bu bilgiler müşteri/cari kart değildir. Abone firmanın kendi profilidir.',
        ];
    }

    private function buildUserRoleSummary(TenantAccount $tenant): array
    {
        $assignments = UserRole::query()
            ->with(['role', 'user'])
            ->where('tenant_account_id', $tenant->id)
            ->get();
        $users = $assignments->pluck('user')->filter()->unique('id')->values();
        $tenantOwnerRoleId = Role::query()->where('key', 'tenant_owner')->where('is_active', true)->value('id');

        return [
            'total_users' => $users->count(),
            'active_users' => $users->count(),
            'has_panel_owner' => $tenantOwnerRoleId ? $assignments->contains(fn (UserRole $assignment) => (int) $assignment->role_id === (int) $tenantOwnerRoleId) : false,
            'has_finance_user' => $users->contains(fn (User $user) => $user->hasAnyPermissionInTenant([
                'view_order_finance_summary',
                'view_payment_details',
                'manage_payments',
                'mark_payments_received',
            ], $tenant->id)),
            'has_operations_user' => $users->contains(fn (User $user) => $user->hasAnyPermissionInTenant([
                'view_graphics',
                'view_procurements',
                'view_productions',
                'view_deliveries',
            ], $tenant->id)),
            'users_route' => Route::has('admin.users.index') ? route('admin.users.index') : null,
            'roles_route' => null,
        ];
    }

    private function buildStorageSummary(string $workFolderRootName): array
    {
        return [
            'root_name' => $workFolderRootName,
            'preview_path' => $workFolderRootName . ' / ABC-INSAAT-AS / SP-2026-0008 / 01-AK-1020-KIRMIZI',
            'storage_label' => 'Yerel sunucu',
            'planned_providers' => ['Amazon S3', 'Google Drive', 'Yandex Disk', 'CDN / dosya arşivi'],
            'note' => 'Bu entegrasyonlar aktif edilmeden erişim bilgisi veya şifre girilmez.',
        ];
    }

    private function buildDeliveryTypeSummary(TenantAccount $tenant): array
    {
        $types = $this->tenantDeliveryTypeService->ensureDefaultsForTenant($tenant);
        $default = $types->firstWhere('is_default', true);

        return [
            'count' => $types->count(),
            'active_count' => $types->where('is_active', true)->count(),
            'default_label' => $default?->name ?: 'Henüz seçilmedi',
            'route' => Route::has('admin.settings.delivery-types.index') ? route('admin.settings.delivery-types.index') : null,
        ];
    }

    private function buildCompanyProfileEditSummary(TenantAccount $tenant, array $profile): array
    {
        $displayNameReady = filled($profile['display_name']);
        $legalNameReady = filled($profile['legal_name']);
        $taxReady = filled($profile['tax_office']) && filled($profile['tax_number']);
        $contactReady = filled($profile['phone']) || filled($profile['email']);
        $addressReady = filled($profile['address']) || filled($profile['city']) || filled($profile['district']);
        $websiteReady = filled($profile['website']);
        $logoStatus = filled($profile['logo_url']) ? 'Hazır' : 'Sonraki Faz';

        $profileChecklist = [
            ['label' => 'Görünen firma adı', 'status' => $displayNameReady ? 'Hazır' : 'Eksik'],
            ['label' => 'Yasal unvan', 'status' => $legalNameReady ? 'Hazır' : 'Eksik'],
            ['label' => 'Vergi bilgisi', 'status' => $taxReady ? 'Hazır' : 'Eksik'],
            ['label' => 'Logo', 'status' => $logoStatus],
            ['label' => 'İletişim', 'status' => $contactReady ? 'Hazır' : 'Eksik'],
            ['label' => 'Adres', 'status' => $addressReady ? 'Hazır' : 'Eksik'],
        ];

        $completionEligibleCount = collect($profileChecklist)
            ->reject(fn (array $item) => $item['status'] === 'Sonraki Faz')
            ->count();
        $completionReadyCount = collect($profileChecklist)
            ->where('status', 'Hazır')
            ->count();
        $completionPercent = $completionEligibleCount > 0
            ? (int) round(($completionReadyCount / $completionEligibleCount) * 100)
            : 0;

        $completionMessage = $completionPercent >= 85
            ? 'Profil büyük ölçüde hazır.'
            : ($completionPercent >= 50 ? 'Profilde birkaç eksik var.' : 'Profil temel alanlarda tamamlanmalı.');

        return [
            'checklist' => $profileChecklist,
            'status_cards' => [
                ['label' => 'Belge başlığı', 'status' => $displayNameReady && $legalNameReady ? 'Hazır' : 'Eksik'],
                ['label' => 'Logo', 'status' => filled($profile['logo_url']) ? 'Hazır' : 'Sınırlı'],
                ['label' => 'Vergi bilgisi', 'status' => $taxReady ? 'Hazır' : 'Eksik'],
                ['label' => 'Web sitesi', 'status' => $websiteReady ? 'Tanımlı' : 'Eksik'],
            ],
            'usage_areas' => [
                ['label' => 'Teklif PDF’i', 'note' => 'üst bilgi'],
                ['label' => 'İş formu', 'note' => 'firma alanı'],
                ['label' => 'Müşteri ekranı', 'note' => 'iletişim'],
                ['label' => 'Bildirim şablonları', 'note' => 'imza'],
            ],
            'preview_title' => trim(collect([$profile['display_name'] ?: null, $profile['legal_name'] ?: null])->filter()->implode(' · ')) ?: 'Firma bilgileri eksik',
            'logo_note' => 'Logo yükleme bu fazda sınırlı tutulur; çalışan gibi görünen upload alanı açılmaz.',
            'tenant_label' => $tenant->name,
            'completion_percent' => $completionPercent,
            'completion_message' => $completionMessage,
            'completion_note' => 'Vergi bilgisi, adres ve iletişim alanları tamamlandığında belge üst bilgileri daha güvenli görünür.',
            'preview_brand' => filled($profile['logo_url'])
                ? 'Logo'
                : Str::upper(Str::substr((string) ($profile['display_name'] ?: $tenant->name ?: 'PF'), 0, 2)),
            'preview_website' => $profile['website'] ?: 'Web sitesi eklenmedi',
            'preview_location' => collect([$profile['country'] ?? null, $profile['city'] ?? null])->filter()->implode(' / ') ?: 'Konum bilgisi eksik',
            'status_chips' => [
                'currency' => $tenant->default_currency ?: 'TL',
                'tenant_status' => $tenant->status === 'active' ? 'Aktif' : Str::headline((string) $tenant->status),
            ],
        ];
    }

    private function buildTenantModuleCategories(array $modules, array $features): array
    {
        $definitions = [
            [
                'label' => 'Çekirdek Satış ve Sipariş',
                'items' => [
                    ['type' => 'module', 'key' => 'order_flow', 'label' => 'Teklif ve Sipariş'],
                    ['type' => 'module', 'key' => 'current_accounts', 'label' => 'Cari Kartlar'],
                    ['type' => 'module', 'key' => 'finance', 'label' => 'Finans'],
                ],
            ],
            [
                'label' => 'Katalog ve Product Hub',
                'items' => [
                    ['type' => 'module', 'key' => 'advanced_catalog', 'label' => 'Ürün ve Katalog'],
                    ['type' => 'module', 'key' => 'product_data_hub', 'label' => 'Product Data Hub'],
                    ['type' => 'feature', 'module_key' => 'advanced_catalog', 'key' => 'product_variants', 'label' => 'Gelişmiş Katalog'],
                    ['type' => 'module', 'key' => 'supplier_feed', 'label' => 'Tedarikçi Feed'],
                ],
            ],
            [
                'label' => 'Operasyon',
                'items' => [
                    ['type' => 'module', 'key' => 'graphics', 'label' => 'Grafik'],
                    ['type' => 'module', 'key' => 'procurement', 'label' => 'Tedarik'],
                    ['type' => 'module', 'key' => 'production', 'label' => 'Üretim'],
                    ['type' => 'module', 'key' => 'delivery', 'label' => 'Teslimat'],
                    ['type' => 'module', 'key' => 'print_settings', 'label' => 'Baskı Ayarları'],
                ],
            ],
            [
                'label' => 'Müşteri Portalı ve Paylaşım',
                'items' => [
                    ['type' => 'module', 'key' => 'customer_portal', 'label' => 'Müşteri Portalı'],
                    ['type' => 'module', 'key' => 'quote_customer_approval', 'label' => 'Müşteri Teklif Onayı'],
                    ['type' => 'module', 'key' => 'graphic_customer_approval', 'label' => 'Grafik Müşteri Onayı'],
                    ['type' => 'feature', 'module_key' => 'customer_portal', 'key' => 'portal_orders', 'label' => 'Müşteri Takip Bağlantıları'],
                ],
            ],
            [
                'label' => 'Bildirim ve Raporlama',
                'items' => [
                    ['type' => 'module', 'key' => 'notification_center', 'label' => 'Bildirim Merkezi'],
                    ['type' => 'module', 'key' => 'reporting', 'label' => 'Raporlama'],
                ],
            ],
            [
                'label' => 'Entegrasyonlar',
                'items' => [
                    ['type' => 'module', 'key' => 'api_access', 'label' => 'API Erişimi'],
                    ['type' => 'module', 'key' => 'xml_import_export', 'label' => 'XML / JSON Aktarım'],
                    ['type' => 'module', 'key' => 'custom_domain', 'label' => 'Özel Domain'],
                ],
            ],
        ];

        return collect($definitions)->map(function (array $category) use ($modules, $features): array {
            $items = collect($category['items'])->map(function (array $definition) use ($modules, $features): array {
                $state = $definition['type'] === 'module'
                    ? ($modules[$definition['key']] ?? null)
                    : ($features[$definition['key']] ?? null);
                $catalogDefinition = $definition['type'] === 'module'
                    ? $this->catalogService->getModule($definition['key'])
                    : $this->catalogService->getFeature($definition['key']);

                $statusLabel = 'Pakette Yok';
                $tone = 'gray';

                if (($catalogDefinition['status'] ?? null) === 'planned' || ($catalogDefinition['status'] ?? null) === 'passive') {
                    $statusLabel = 'Sonraki Faz';
                } elseif (($state['enabled'] ?? false) === true) {
                    $statusLabel = 'Aktif';
                    $tone = 'green';
                } elseif (($catalogDefinition['status'] ?? null) === 'deprecated') {
                    $statusLabel = 'Sonraki Faz';
                } else {
                    $tone = 'amber';
                }

                return [
                    'label' => $definition['label'],
                    'description' => $catalogDefinition['description'] ?? null,
                    'status_label' => $statusLabel,
                    'status_tone' => $tone,
                ];
            })->all();

            return [
                'label' => $category['label'],
                'items' => $items,
            ];
        })->all();
    }

    private function quickLink(string $label, ?string $url): ?array
    {
        if (! filled($url)) {
            return null;
        }

        return [
            'label' => $label,
            'url' => $url,
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
        $host = $this->panelBaseHost();
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
            'panel_url' => $panelHost ? $this->preferredScheme() . '://' . $panelHost . '/admin' : null,
            'panel_host' => $panelHost,
            'has_first_company' => $tenant->companies()->exists(),
            'has_first_current_account' => $tenant->currentAccounts()->exists(),
            'smtp_ready' => filled($settings['smtp_from_email'] ?? null) || filled($settings['smtp_host'] ?? null),
            'whatsapp_ready' => filled($settings['whatsapp_test_phone'] ?? null)
                || filled($settings['whatsapp_default_signature'] ?? null),
            'legacy_import_done' => false,
        ];
    }

    private function panelBaseHost(): string
    {
        $host = strtolower(trim((string) config('prodelya_domains.panel_domain')));

        if ($host !== '') {
            return $host;
        }

        $fallback = strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST)));

        return $fallback !== '' ? $fallback : 'prodelya_core.test';
    }

    private function preferredScheme(): string
    {
        return config('prodelya_domains.force_https') ? 'https' : 'http';
    }
}
