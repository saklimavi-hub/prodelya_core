<?php

namespace App\Services;

use App\Models\Role;
use App\Models\TenantCatalogProduct;
use App\Models\TenantAccount;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

class SuperAdminDashboardSummaryService
{
    public function __construct(
        private readonly TenantSubscriptionStatusService $subscriptionStatusService,
        private readonly TenantUsageService $tenantUsageService,
        private readonly TenantAccessService $tenantAccessService,
        private readonly TenantOnboardingStatusService $tenantOnboardingStatusService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $rows = $this->tenantRows();
        $demoRows = $rows->where('is_demo_tenant', true)->values();
        $liveCandidateRows = $rows->where('is_live_candidate', true)->values();
        $reviewRows = $rows->filter(fn (array $row) => !$row['is_demo_tenant'] && !$row['is_live_candidate'])->values();

        $summaryCards = [
            $this->summaryCard('Toplam Abone Firma', $rows->count(), 'Genel görünüm', 'blue'),
            $this->summaryCard('Aktif Abone Firma', $rows->where('subscription.status', 'active')->count(), 'Canlı erişime açık', 'green'),
            $this->summaryCard('Deneme Sürecinde', $rows->where('subscription.status', 'trial')->count(), 'Süre takibi gerekli', 'blue'),
            $this->summaryCard('Süresi Dolmuş / Askıda', $rows->filter(fn (array $row) => in_array($row['subscription']['status'], ['expired', 'suspended'], true))->count(), 'Erişim ve paket kontrolü', 'red'),
            $this->summaryCard('Paket / Limit Uyarısı Olan', $rows->where('has_usage_warning', true)->count(), 'Kullanım eşiği yaklaşanlar', 'amber'),
            $this->summaryCard('Onboarding Eksik Olan', $rows->where('onboarding_complete', false)->count(), 'Hazırlık adımları eksik', 'amber'),
            $this->summaryCard('Domain / Panel Eksik Olan', $rows->where('has_panel_or_domain_gap', true)->count(), 'Panel veya domain ayarı bekleyenler', 'amber'),
            $this->summaryCard('Son 7 Gün Açılan', $rows->filter(fn (array $row) => $row['created_at']?->gte(now()->subDays(7)))->count(), 'Yeni eklenen Abone Firmalar', 'slate'),
        ];

        $warnings = array_values(array_filter([
            $this->warningBlock(
                'owner_missing',
                'Owner kullanıcısı eksik olanlar',
                'Abone Firma günlük operasyonu için önce owner hesabı hazırlanmalı.',
                $rows->filter(fn (array $row) => !$row['owner_exists']),
                'amber'
            ),
            $this->warningBlock(
                'onboarding_missing',
                'Onboarding eksik olanlar',
                'Başlangıç ayarları, bildirim şablonları veya temel tenant defaults tamamlanmalı.',
                $rows->filter(fn (array $row) => !$row['onboarding_complete']),
                'amber'
            ),
            $this->warningBlock(
                'panel_domain_missing',
                'Panel / domain ayarı eksik olanlar',
                'Panel subdomain, custom domain veya portal domain bilgisi eksik.',
                $rows->filter(fn (array $row) => $row['has_panel_or_domain_gap']),
                'amber'
            ),
            $this->warningBlock(
                'trial_expiring',
                'Deneme süresi bitmek üzere olanlar',
                'Kalan günü azalan trial tenantlar satış / aktivasyon takibi ister.',
                $rows->filter(function (array $row): bool {
                    $daysRemaining = $row['subscription']['days_remaining'];

                    return $row['subscription']['status'] === 'trial'
                        && $daysRemaining !== null
                        && $daysRemaining >= 0
                        && $daysRemaining <= 7;
                }),
                'blue'
            ),
            $this->warningBlock(
                'restricted_status',
                'Süresi dolmuş veya askıda olanlar',
                'Bu tenantlar için paket, erişim veya destek kararı gerekebilir.',
                $rows->filter(fn (array $row) => in_array($row['subscription']['status'], ['expired', 'suspended'], true)),
                'red'
            ),
            $this->warningBlock(
                'usage_warning',
                'Paket / limit uyarısı olanlar',
                'Kullanım uyarısı veya limit aşımı görünen tenantlar.',
                $rows->filter(fn (array $row) => $row['has_usage_warning']),
                'red'
            ),
            ]));

        $liveReadinessCards = [
            $this->summaryCard('Canlıya Hazır Abone Firma', $liveCandidateRows->count(), 'Owner, paket, panel, firma ve SMTP hazır', 'green'),
            $this->summaryCard('Kontrol Gerektiren Abone Firma', $reviewRows->count(), 'Eksik onboarding veya operasyon kontrolü bekliyor', 'amber'),
            $this->summaryCard('Demo/Test Abone Firma', $demoRows->count(), 'Canlı tenantlardan ayrı izlenmeli', 'blue'),
            $this->summaryCard('Domain / Panel Eksik', $rows->where('has_panel_or_domain_gap', true)->count(), 'Panel veya portal görünürlüğü eksik', 'amber'),
            $this->summaryCard('SMTP Eksik', $rows->where('smtp_ready', false)->count(), 'Bildirim kurulumu eksik tenantlar', 'amber'),
            $this->summaryCard('Storage Kontrol Edilmeli', $rows->count(), 'Dosya depolama ve public link üretimi deploy öncesi doğrulanmalı', 'slate'),
            $this->summaryCard('Lifecycle Uyarısı Olan', $rows->filter(fn (array $row) => $row['has_lifecycle_warning'])->count(), 'Bitiş tarihi yaklaşan veya erişimi kısıtlı tenantlar', 'red'),
            $this->summaryCard('Paket / Limit Uyarısı Olan', $rows->where('has_usage_warning', true)->count(), 'Limit yaklaşan tenantlar', 'amber'),
        ];

        $packageBreakdown = $rows
            ->groupBy(fn (array $row) => $row['package_label'])
            ->map(fn (Collection $group, string $label) => [
                'label' => $label,
                'count' => $group->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'summaryCards' => $summaryCards,
            'liveReadinessCards' => $liveReadinessCards,
            'warnings' => $warnings,
            'recentTenants' => $rows
                ->filter(fn (array $row) => $row['created_at']?->gte(now()->subDays(7)))
                ->sortByDesc(fn (array $row) => $row['created_at']?->timestamp ?? 0)
                ->take(7)
                ->values()
                ->all(),
            'packageBreakdown' => $packageBreakdown,
            'onboardingIssues' => $rows
                ->filter(fn (array $row) => !$row['onboarding_complete'])
                ->take(10)
                ->values()
                ->all(),
            'operationalNotes' => $this->operationalNotes($rows),
            'systemReadinessChecklist' => $this->systemReadinessChecklist($rows),
            'demoDataChecklist' => $this->demoDataChecklist($demoRows),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function tenantRows(): Collection
    {
        return TenantAccount::query()
            ->with(['modules', 'package'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TenantAccount $tenant) => $this->tenantRow($tenant))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantRow(TenantAccount $tenant): array
    {
        $subscription = $this->subscriptionStatusService->getStatus($tenant);
        $usageWarnings = $this->tenantUsageService->warningItems($tenant);
        $onboarding = $this->tenantOnboardingStatusService->forTenant($tenant);
        $ownerAssignment = $this->ownerAssignment($tenant);
        $ownerUser = $ownerAssignment?->user;
        $activeModuleCount = count(array_filter(
            $this->tenantAccessService->effectiveAccessSummary($tenant)['modules'] ?? [],
            fn (array $row) => (bool) ($row['enabled'] ?? false)
        ));
        $missingOnboardingKeys = collect($onboarding)
            ->filter(fn ($value, string $key) => str_starts_with($key, 'has_') && $value === false)
            ->keys()
            ->values()
            ->all();
        $isDemoTenant = $this->isDemoTenant($tenant);
        $hasPanelOrDomainGap = blank($tenant->panel_subdomain)
            || (blank($tenant->custom_domain) && blank($tenant->portal_domain));
        $hasLifecycleWarning = in_array($subscription['status'], ['expired', 'suspended', 'passive'], true)
            || (
                $subscription['days_remaining'] !== null
                && $subscription['days_remaining'] >= 0
                && $subscription['days_remaining'] <= 7
            );
        $smtpReady = (bool) ($onboarding['has_smtp_config'] ?? false);
        $isLiveCandidate = !$isDemoTenant
            && in_array($subscription['status'], ['active', 'trial'], true)
            && empty($usageWarnings)
            && !$hasPanelOrDomainGap
            && (bool) ($onboarding['has_package'] ?? false)
            && (bool) ($onboarding['has_active_owner'] ?? false)
            && (bool) ($onboarding['has_active_user'] ?? false)
            && (bool) ($onboarding['has_company_profile'] ?? false)
            && (bool) ($onboarding['has_tenant_settings_defaults'] ?? false)
            && $smtpReady;

        return [
            'tenant' => $tenant,
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'package_label' => $tenant->package?->name ?? ($tenant->package_key ?: 'Core'),
            'package_key' => $tenant->package_key ?: 'core',
            'subscription' => $subscription,
            'usage_warning_count' => count($usageWarnings),
            'has_usage_warning' => !empty($usageWarnings),
            'onboarding' => $onboarding,
            'onboarding_complete' => empty($missingOnboardingKeys),
            'missing_onboarding_keys' => $missingOnboardingKeys,
            'owner_exists' => $ownerAssignment !== null,
            'owner_name' => $ownerUser?->name,
            'is_demo_tenant' => $isDemoTenant,
            'is_live_candidate' => $isLiveCandidate,
            'smtp_ready' => $smtpReady,
            'panel_subdomain' => (string) $tenant->panel_subdomain,
            'custom_domain' => (string) ($tenant->custom_domain ?? ''),
            'portal_domain' => (string) ($tenant->portal_domain ?? ''),
            'has_panel_or_domain_gap' => $hasPanelOrDomainGap,
            'has_lifecycle_warning' => $hasLifecycleWarning,
            'active_module_count' => $activeModuleCount,
            'last_activity_at' => $ownerUser?->last_login_at ?: $tenant->updated_at ?: $tenant->created_at,
            'created_at' => $tenant->created_at instanceof Carbon ? $tenant->created_at : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryCard(string $label, int $value, string $helper, string $tone): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'helper' => $helper,
            'tone' => $tone,
        ];
    }

    private function warningBlock(
        string $key,
        string $title,
        string $description,
        Collection $rows,
        string $tone
    ): ?array {
        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'key' => $key,
            'title' => $title,
            'description' => $description,
            'count' => $rows->count(),
            'tone' => $tone,
            'items' => $rows->take(5)->map(function (array $row): array {
                return [
                    'tenant_id' => $row['tenant_id'],
                    'name' => $row['name'],
                    'package_label' => $row['package_label'],
                    'status_label' => $row['subscription']['label'],
                    'days_remaining' => $row['subscription']['days_remaining'],
                ];
            })->values()->all(),
        ];
    }

    private function ownerAssignment(TenantAccount $tenant): ?UserRole
    {
        $ownerRoleId = Role::query()
            ->where('key', 'tenant_owner')
            ->where('is_active', true)
            ->value('id');

        if (!$ownerRoleId) {
            return null;
        }

        return UserRole::query()
            ->with('user')
            ->where('tenant_account_id', $tenant->id)
            ->where('role_id', $ownerRoleId)
            ->orderBy('id')
            ->first();
    }

    private function isDemoTenant(TenantAccount $tenant): bool
    {
        $panelSubdomain = strtolower(trim((string) $tenant->panel_subdomain));
        $slug = strtolower(trim((string) $tenant->slug));
        $packageKey = strtolower(trim((string) ($tenant->package_key ?? '')));

        return $panelSubdomain === 'demo'
            || in_array($slug, ['demo', 'demo-sirketi'], true)
            || $packageKey === 'demo';
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function systemReadinessChecklist(Collection $rows): array
    {
        $publicDiskConfigured = (bool) Config::get('filesystems.disks.public.root');
        $publicLinkConfigured = array_key_exists(public_path('storage'), Config::get('filesystems.links', []));
        $queueConnection = (string) Config::get('queue.default', 'sync');
        $logChannel = (string) Config::get('logging.default', Config::get('logging.channels.stack.driver', 'stack'));
        $catalogReadyCount = $rows->filter(function (array $row): bool {
            $onboarding = $row['onboarding'] ?? [];

            return (bool) ($onboarding['has_package'] ?? false)
                && (bool) ($onboarding['has_panel_domain'] ?? false)
                && !($row['has_panel_or_domain_gap'] ?? false);
        })->count();

        return [
            [
                'label' => 'Dosya Depolama / Public Storage',
                'status' => $publicDiskConfigured && $publicLinkConfigured ? 'Kontrol Edilmeli' : 'Eksik',
                'message' => $publicDiskConfigured && $publicLinkConfigured
                    ? 'Public disk ve storage link tanımı var; deploy ortamında yazılabilirlik ayrıca doğrulanmalı.'
                    : 'filesystems public disk veya storage link tanımı eksik görünüyor.',
            ],
            [
                'label' => 'SMTP / WhatsApp Hazırlığı',
                'status' => $rows->where('smtp_ready', false)->isEmpty() ? 'Hazır' : 'Kontrol Edilmeli',
                'message' => $rows->where('smtp_ready', false)->isEmpty()
                    ? 'Tüm Abone Firmalarda temel SMTP bilgisi görünüyor. WhatsApp hazır mesaj linki tenant bazlı ayrıca izlenmeli.'
                    : $rows->where('smtp_ready', false)->count() . ' Abone Firmada SMTP ayarı eksik veya pasif.',
            ],
            [
                'label' => 'Kuyruk İşleyici',
                'status' => 'Kontrol Edilmeli',
                'message' => 'QUEUE_CONNECTION=' . $queueConnection . '. Canlı worker kurulumu bu fazda otomatik doğrulanmaz.',
            ],
            [
                'label' => 'Zamanlayıcı / Scheduler',
                'status' => 'Kontrol Edilmeli',
                'message' => 'Cron veya task scheduler kurulumu deploy sırasında ayrıca doğrulanmalı.',
            ],
            [
                'label' => 'Yedekleme Planı',
                'status' => 'Kontrol Edilmeli',
                'message' => 'Bu fazda otomatik yedekleme kurulmaz; canlı öncesi manuel plan netleşmelidir.',
            ],
            [
                'label' => 'Sistem Kaydı / Log İzleme',
                'status' => 'Kontrol Edilmeli',
                'message' => 'LOG_CHANNEL=' . $logChannel . '. Merkezi izleme ve alarm akışı ayrıca doğrulanmalı.',
            ],
            [
                'label' => 'Product Hub → Katalog → Teklif Arama',
                'status' => $catalogReadyCount > 0 ? 'Kontrol Edilmeli' : 'Eksik',
                'message' => $catalogReadyCount > 0
                    ? $catalogReadyCount . ' Abone Firmada temel katalog/panel hazırlığı görünüyor; son full smoke ile tekrar doğrulanmalı.'
                    : 'Katalog ve teklif arama zinciri için hazır tenant görünmüyor.',
            ],
            [
                'label' => 'Public Tracking Güvenliği',
                'status' => 'Kontrol Edilmeli',
                'message' => 'Public tracking ve iş formu güvenliği full smoke / security testleri ile doğrulanır.',
            ],
            [
                'label' => 'Finans Yetki Güvenliği',
                'status' => 'Kontrol Edilmeli',
                'message' => 'Finans görünürlüğü yetki testleri ile doğrulanır; public ve düşük yetkili yüzeylere sızma olmamalıdır.',
            ],
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $demoRows
     * @return array<int, array<string, mixed>>
     */
    private function demoDataChecklist(Collection $demoRows): array
    {
        $demoTenantIds = $demoRows->pluck('tenant_id')->filter()->values();
        $hasDemoTenants = $demoTenantIds->isNotEmpty();
        $demoUsersCount = $hasDemoTenants
            ? UserRole::query()
                ->whereIn('tenant_account_id', $demoTenantIds)
                ->whereHas('user', fn ($query) => $query->where('is_platform_admin', false))
                ->distinct('user_id')
                ->count('user_id')
            : 0;
        $demoCompaniesCount = $hasDemoTenants
            ? TenantAccount::query()->whereKey($demoTenantIds)->withCount('companies')->get()->sum('companies_count')
            : 0;
        $demoCurrentAccountsCount = $hasDemoTenants
            ? TenantAccount::query()->whereKey($demoTenantIds)->withCount('currentAccounts')->get()->sum('current_accounts_count')
            : 0;
        $demoOrdersCount = $hasDemoTenants
            ? TenantAccount::query()->whereKey($demoTenantIds)->withCount('orders')->get()->sum('orders_count')
            : 0;
        $demoCatalogCount = $hasDemoTenants
            ? TenantCatalogProduct::query()->whereIn('tenant_account_id', $demoTenantIds)->count()
            : 0;

        return [
            [
                'label' => 'Demo/Test Abone Firma',
                'status' => $hasDemoTenants ? 'Demo/Test' : 'Sonraki Faz',
                'message' => $hasDemoTenants
                    ? $demoRows->count() . ' demo/test Abone Firma mevcut. Mevcut operasyonel ayrım demo panel kimliği / demo paket anahtarı üzerinden izleniyor.'
                    : 'Explicit demo alanı sonraki faz. Şu an ayrı demo/test tenant kaydı görünmüyor.',
            ],
            [
                'label' => 'Demo Kullanıcılar',
                'status' => $hasDemoTenants ? 'Kontrol Edilmeli' : 'Sonraki Faz',
                'message' => $hasDemoTenants
                    ? $demoUsersCount . ' demo tenant kullanıcısı tespit edildi.'
                    : 'Demo tenant yoksa kullanıcı temizliği ayrı takip edilir.',
            ],
            [
                'label' => 'Demo Firma / Cari',
                'status' => $hasDemoTenants ? 'Kontrol Edilmeli' : 'Sonraki Faz',
                'message' => $hasDemoTenants
                    ? $demoCompaniesCount . ' firma ve ' . $demoCurrentAccountsCount . ' cari demo tenant içinde görünüyor.'
                    : 'Demo firma/cari ayrımı için tenant bazlı kontrol gerekir.',
            ],
            [
                'label' => 'Demo Teklif / Sipariş / Katalog',
                'status' => $hasDemoTenants ? 'Kontrol Edilmeli' : 'Sonraki Faz',
                'message' => $hasDemoTenants
                    ? $demoOrdersCount . ' sipariş/teklif ve ' . $demoCatalogCount . ' katalog ürünü demo tenant tarafında görünüyor.'
                    : 'Demo sipariş ve katalog kayıtları için ayrı tenant bulunmuyor.',
            ],
            [
                'label' => 'Demo Public Tracking / Upload',
                'status' => 'Kontrol Edilmeli',
                'message' => 'Bu fazda otomatik silme yapılmaz. Public tracking ve upload temizliği tenant bazlı manuel checklist ile doğrulanmalıdır.',
            ],
            [
                'label' => 'Gerçek Tenant ile Karışma',
                'status' => 'Kontrol Edilmeli',
                'message' => 'Tenant isolation ve demo context davranışı security smoke testleri ile doğrulanır.',
            ],
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function operationalNotes(Collection $rows): array
    {
        return [
            [
                'label' => 'Queue / Scheduler',
                'status' => 'Kontrol Edilmeli',
                'message' => 'Kuyruk işleyici ve cron görünürlüğü panelde raporlanıyor; canlı ortam worker/cron kurulumu deploy sırasında ayrıca doğrulanmalı.',
            ],
            [
                'label' => 'SaaS Cari / Hizmet',
                'status' => 'Hazır',
                'message' => 'Tenant SaaS cari ve hizmet kataloğu operasyonel kullanım için hazır. Online ödeme katmanı sonraki ticari fazda eklenecek.',
            ],
            [
                'label' => 'Başvuru / Paket Talep Geçmişi',
                'status' => 'Hazır',
                'message' => 'Signup ve paket talepleri için status / apply / convert operasyon izleri merkezi audit log üzerinden tutulur.',
            ],
            [
                'label' => 'Ortak Ödeme Motoru',
                'status' => 'Sonraki Faz',
                'message' => 'Provider-agnostic gateway temeli planlandı; Super Admin tarafında ortak omurga sabit, tenant tarafında modül olarak açılacak.',
            ],
            [
                'label' => 'Canlı Adayı Tenantlar',
                'status' => $rows->where('is_live_candidate', true)->isNotEmpty() ? 'Hazır' : 'Kontrol Edilmeli',
                'message' => $rows->where('is_live_candidate', true)->isNotEmpty()
                    ? $rows->where('is_live_candidate', true)->count() . ' tenant canlı aday kriterlerini karşılıyor.'
                    : 'Henüz tüm readiness adımlarını aynı anda karşılayan tenant görünmüyor.',
            ],
        ];
    }
}
