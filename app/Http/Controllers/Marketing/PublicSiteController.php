<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\TenantSignupRequest;
use App\Services\ModuleFeatureCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PublicSiteController extends Controller
{
    public function __construct(
        protected ModuleFeatureCatalogService $catalogService
    ) {
    }

    public function home(): View
    {
        return view('welcome', [
            'packages' => $this->publicPackages(),
            'packageCards' => $this->publicPackageCards(),
            'moduleHighlights' => $this->homeModuleHighlights(),
            'workflowSteps' => $this->workflowSteps(),
            'problemPoints' => $this->problemPoints(),
            'supplierExamples' => $this->supplierExamples(),
            'moduleStoryGroups' => $this->moduleStoryGroups(),
            'securityHighlights' => $this->securityHighlights(),
            'demoTopicOptions' => $this->demoTopicOptions(),
        ]);
    }

    public function registerInterest(): View
    {
        return view('marketing.register-interest', [
            'packageOptions' => $this->publicPackages(),
            'moduleOptions' => $this->publicModuleOptions(),
        ]);
    }

    public function storeRegisterInterest(Request $request): RedirectResponse
    {
        $packages = $this->publicPackages();
        $moduleOptions = $this->publicModuleOptions();

        $validated = $request->validate([
            'website' => ['nullable', 'string', 'max:10'],
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'business_type' => ['nullable', 'string', 'max:100'],
            'requested_package_id' => ['nullable', 'integer', Rule::in($packages->pluck('id')->all())],
            'expected_user_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'selected_modules' => ['nullable', 'array'],
            'selected_modules.*' => ['string', Rule::in(array_keys($moduleOptions))],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [
            'company_name.required' => 'Firma adı zorunludur.',
            'contact_name.required' => 'Yetkili adı soyadı zorunludur.',
            'phone.required' => 'Telefon zorunludur.',
            'email.required' => 'E-posta zorunludur.',
            'email.email' => 'Geçerli bir e-posta adresi girin.',
        ]);

        if (filled($validated['website'] ?? null)) {
            return redirect()
                ->route('marketing.register-interest')
                ->with('success', 'Başvurunuz alındı. Prodelya ekibi sizinle en kısa sürede iletişime geçecek.');
        }

        $package = $packages->firstWhere('id', (int) ($validated['requested_package_id'] ?? 0));

        TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_TRIAL,
            'company_name' => trim((string) $validated['company_name']),
            'contact_name' => trim((string) $validated['contact_name']),
            'phone' => trim((string) $validated['phone']),
            'email' => trim((string) $validated['email']),
            'city' => trim((string) ($validated['city'] ?? '')),
            'sector' => trim((string) ($validated['business_type'] ?? '')),
            'requested_package_id' => $package?->id,
            'requested_package_key' => $package?->key,
            'requested_modules_json' => array_values($validated['selected_modules'] ?? []),
            'expected_user_count' => $validated['expected_user_count'] ?? null,
            'note' => trim((string) ($validated['message'] ?? '')),
            'status' => TenantSignupRequest::STATUS_NEW,
            'source' => 'public_landing',
            'meta_json' => [
                'submitted_host' => $request->getHost(),
                'landing_path' => '/register-interest',
            ],
        ]);

        return redirect()
            ->route('marketing.register-interest')
            ->with('success', 'Başvurunuz alındı. Prodelya ekibi sizinle en kısa sürede iletişime geçecek.');
    }

    public function demoRequest(): View
    {
        return view('marketing.demo-request', [
            'demoTopicOptions' => $this->demoTopicOptions(),
        ]);
    }

    public function storeDemoRequest(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'website' => ['nullable', 'string', 'max:10'],
            'company_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
            'demo_topic' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        if (filled($validated['website'] ?? null)) {
            return redirect()
                ->route('marketing.demo-request')
                ->with('success', 'Demo talebiniz alındı. Size uygun demo akışı için ekibimiz sizinle iletişime geçecek.');
        }

        TenantSignupRequest::query()->create([
            'request_type' => TenantSignupRequest::TYPE_DEMO,
            'company_name' => trim((string) $validated['company_name']),
            'contact_name' => trim((string) $validated['contact_name']),
            'phone' => trim((string) $validated['phone']),
            'email' => trim((string) $validated['email']),
            'demo_topic' => trim((string) $validated['demo_topic']),
            'note' => trim((string) ($validated['message'] ?? '')),
            'status' => TenantSignupRequest::STATUS_NEW,
            'source' => 'public_landing',
            'meta_json' => [
                'submitted_host' => $request->getHost(),
                'landing_path' => '/demo-talep',
            ],
        ]);

        return redirect()
            ->route('marketing.demo-request')
            ->with('success', 'Demo talebiniz alındı. Size uygun demo akışı için ekibimiz sizinle iletişime geçecek.');
    }

    /**
     * @return Collection<int, Package>
     */
    protected function publicPackages(): Collection
    {
        if (! Schema::hasTable('packages')) {
            return collect();
        }

        $packages = Package::query()
            ->with('limits')
            ->where('status', 'active')
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($packages->isEmpty()) {
            $packages = Package::query()
                ->with('limits')
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        return $packages->values();
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function homeModuleHighlights(): array
    {
        $catalog = collect($this->catalogService->modules())->keyBy('key');
        $descriptions = [
            'order_flow' => 'Tekliften siparişe uzanan satış ve operasyon omurgasını tek panelde yönetin.',
            'graphics' => 'Grafik onayı, revize ve müşteri geri bildirimlerini kontrollü şekilde toplayın.',
            'work_forms' => 'Dosya, iş formu ve operasyon belgelerini ekipler arası görünür hale getirin.',
            'customer_portal' => 'Müşteri Portalı, Abone Firma’nın kendi müşterilerine teklif, sipariş, grafik onayı ve teslimat süreçlerini gösterebildiği özel portaldır.',
            'product_data_hub' => 'Tedarikçilerden gelen ürün, stok ve fiyat verilerini tek merkezde toplayın; Abone Firma kataloğunu güvenli şekilde güncel tutun.',
            'supplier_feed' => 'Tedarikçi ürün kaynaklarını tek katalog mantığında birleştirip teklif ekibine temiz veri sunun.',
            'procurement' => 'Tedarik süreçlerini, ürün akışını ve siparişe bağlı satın alma kontrolünü merkezden yönetin.',
            'production' => 'Üretim ve fason akışlarını görünür kılın; ekipler aynı durum bilgisinde buluşsun.',
            'delivery' => 'Teslimat, koli ve takip adımlarını hem iç ekip hem müşteri tarafında güvenli izleyin.',
            'finance' => 'Tahsilat ve finansal görünürlüğü yetki kontrollü olarak ekiplerinize açın.',
            'notification_center' => 'E-posta, WhatsApp hazır mesajları ve bildirim geçmişini aynı merkezde yönetin.',
            'reporting' => 'Karar almayı hızlandıran özet rapor ve yönetim ekranlarıyla operasyonu izleyin.',
            'user_management' => 'Kullanıcı, rol ve yetki yapısını Abone Firma bazlı güvenli şekilde yönetin.',
            'tenant_settings' => 'Paket, limit ve modül erişimini firmanızın büyüklüğüne göre yönetilebilir tutun.',
        ];

        $keys = array_keys($descriptions);
        $highlights = [];

        foreach ($keys as $key) {
            $module = $catalog->get($key);

            if (! is_array($module)) {
                continue;
            }

            $status = (string) ($module['status'] ?? 'passive');

            if (! in_array($status, ['core', 'active'], true)) {
                continue;
            }

            $highlights[] = [
                'key' => $key,
                'label' => (string) ($module['label'] ?? $module['name'] ?? $key),
                'description' => $descriptions[$key],
                'category' => (string) ($module['category'] ?? 'general'),
            ];
        }

        return $highlights;
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function workflowSteps(): array
    {
        return [
            ['title' => 'Teklif hazırlanır', 'description' => 'Ürün, baskı, alternatif seçenek ve fiyatlar tek yerde hazırlanır.'],
            ['title' => 'Müşteri onaylar veya revize ister', 'description' => 'Müşteri güvenli bağlantı üzerinden teklifi onaylar, revize ister veya reddeder.'],
            ['title' => 'Siparişe dönüşür', 'description' => 'Onaylanan teklif tek tıkla siparişe taşınır; doğru kalemler korunur.'],
            ['title' => 'Operasyon işleri ayrılır', 'description' => 'Grafik, baskı, tedarik, üretim ve teslimat işleri ayrı ekip akışlarına düşer.'],
            ['title' => 'Teslimat ve finans takip edilir', 'description' => 'Teslim, tahsilat ve durum görünürlüğü siparişten kopmadan izlenir.'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function publicModuleOptions(): array
    {
        $descriptions = [
            'product_data_hub' => 'Tedarikçi ürünlerini ve kendi katalog ürünlerinizi teklif ekranına hazır yönetin.',
            'advanced_catalog' => 'Ürün varyasyonları, kategori görünürlüğü ve katalog düzenini daha net yönetin.',
            'supplier_feed' => 'Promosyon tedarikçilerinden gelen ürün bilgilerini katalogda kullanın.',
            'customer_portal' => 'Müşterileriniz teklif, sipariş ve dosya bilgilerini güvenli ekranda görsün.',
            'quote_customer_approval' => 'Müşteri teklifinizi onaylasın, revize istesin veya reddetsin.',
            'graphic_customer_approval' => 'Grafik görselleri müşteri onayına güvenli bağlantıyla sunulsun.',
            'notification_center' => 'E-posta ve WhatsApp hazır mesaj süreçlerini yönetin.',
            'reporting' => 'Sipariş, teklif ve operasyon raporlarını takip edin.',
            'api_access' => 'Entegrasyon erişimi ve dış sistem bağlantıları.',
        ];

        $options = [];

        foreach ($this->catalogService->modules() as $key => $module) {
            $status = $module['status'] ?? 'passive';
            $isCore = (bool) ($module['is_core'] ?? false);
            $requiresPackage = (bool) ($module['requires_package'] ?? false);

            if (!in_array($status, ['active'], true) || $isCore || !$requiresPackage) {
                continue;
            }

            $options[$key] = [
                'label' => $this->publicModuleLabel($key, $module['label'] ?? $module['name'] ?? $key),
                'description' => $descriptions[$key] ?? ($module['description'] ?? null),
                'category' => $module['category'] ?? null,
            ];
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    protected function problemPoints(): array
    {
        return [
            'Teklifler WhatsApp, e-posta ve Excel arasında dağılır.',
            'Müşterinin hangi teklifi onayladığını takip etmek zorlaşır.',
            'Grafik, baskı, tedarik ve üretim ekipleri ayrı ayrı takip eder.',
            'Ürün fiyatı, stok ve katalog bilgisi güncel kalmaz.',
            'Teslimat ve tahsilat bilgisi siparişten kopar.',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function supplierExamples(): array
    {
        return [
            'Etkin Promosyon',
            'Akdeniz Promosyon',
            'İlpen Promosyon',
            'Yeni Nesil Promosyon',
            'Pozitron Promosyon',
            'Elma-Soylu Takvim',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function moduleStoryGroups(): array
    {
        return [
            [
                'title' => 'Satış ve Teklif',
                'description' => 'Satış ekibi teklif, sipariş ve cari bilgilerini tek iş akışında yönetir.',
                'items' => [
                    ['label' => 'Promosyon Teklifleri', 'description' => 'Ürün, baskı, alternatif adet ve müşteri onay bağlantısı ile teklif hazırlayın.'],
                    ['label' => 'Sipariş Yönetimi', 'description' => 'Onaylanan teklifleri siparişe dönüştürüp operasyon zincirine aktarın.'],
                    ['label' => 'Müşteri / Cari Kartlar', 'description' => 'Müşteri, tedarikçi ve fason firma kayıtlarını ayrı ayrı yönetin.'],
                    ['label' => 'Finans görünürlüğü', 'description' => 'Tahsilat ve bakiye özetini yalnız yetkili roller görsün.'],
                ],
            ],
            [
                'title' => 'Katalog ve Product Data Hub',
                'description' => 'Tedarikçi ürünleri ve kendi ürünlerinizi tek katalog mantığında yönetin.',
                'items' => [
                    ['label' => 'Ürün ve Katalog', 'description' => 'Katalogdaki ürün ve varyant satırlarını teklif ekibine hazır tutun.'],
                    ['label' => 'Product Data Hub', 'description' => 'Tedarikçi ürün bilgilerini tek merkezde toplayıp katalog görünürlüğüne hazırlayın.'],
                    ['label' => 'Tedarikçi ürün kaynakları', 'description' => 'Örnek kaynaklar üzerinden ürün adı, kodu, fiyatı ve stok bilgisini yönetin.'],
                    ['label' => 'Local ürünler', 'description' => 'Kendi ürünlerinizi tedarikçi ürünlerinden ayrı yönetip teklif ekranında birlikte kullanın.'],
                ],
            ],
            [
                'title' => 'Operasyon Yönetimi',
                'description' => 'Sipariş sonrası grafikten teslimata kadar tüm operasyon işleri aynı sipariş omurgasında ilerler.',
                'items' => [
                    ['label' => 'Grafik', 'description' => 'Her baskı satırı için görsel, revize ve onay akışını izleyin.'],
                    ['label' => 'Tedarik', 'description' => 'Siparişe bağlı tedarik ihtiyaçlarını ve tedarikçi taleplerini yönetin.'],
                    ['label' => 'Üretim', 'description' => 'İç üretim ve fason üretim adımlarını görünür tutun.'],
                    ['label' => 'Teslimat ve İş Formu', 'description' => 'İş formu, sevk ve teslim adımlarını tek iş kaydı üzerinden takip edin.'],
                ],
            ],
            [
                'title' => 'Müşteri Portalı ve Onaylar',
                'description' => 'Müşteri Portalı ana giriş değil, abone firmanın müşterilerine sunduğu kontrollü bir modüldür.',
                'items' => [
                    ['label' => 'Müşteri Portalı', 'description' => 'Müşteriler tekliflerini, siparişlerini ve dosyalarını güvenli bağlantılarla görür.'],
                    ['label' => 'Teklif Onayı', 'description' => 'Müşteri onay, revize veya ret kararını doğrudan teklif üzerinden verir.'],
                    ['label' => 'Grafik Onayı', 'description' => 'Grafik görselleri müşteriye kontrollü şekilde açılır.'],
                    ['label' => 'Sipariş Takibi', 'description' => 'Müşteri teslimat ve iş akışı durumunu maliyet görmeden izler.'],
                ],
            ],
            [
                'title' => 'Bildirimler',
                'description' => 'Ekip ve müşteri iletişimini aynı merkezden yönetin.',
                'items' => [
                    ['label' => 'E-posta Bildirimleri', 'description' => 'Teklif, sipariş ve onay akışlarını e-posta ile destekleyin.'],
                    ['label' => 'WhatsApp Hazır Mesaj', 'description' => 'Hazır mesaj bağlantılarıyla hızlı müşteri bilgilendirmesi yapın.'],
                    ['label' => 'Bildirim Merkezi', 'description' => 'Şablonlar ve gönderim geçmişi üzerinden düzen kurun.'],
                ],
            ],
            [
                'title' => 'SaaS ve Yönetim',
                'description' => 'Paket, modül ve limit görünürlüğüyle sistemi firmanızın ölçeğine göre büyütün.',
                'items' => [
                    ['label' => 'Paketler', 'description' => 'İhtiyacınıza göre modül ve limit seti seçin.'],
                    ['label' => 'Modül erişimi', 'description' => 'İleri modüller pakete veya talebe göre açılabilir.'],
                    ['label' => 'Limitler', 'description' => 'Kullanıcı, katalog ve sipariş limitlerini kontrollü izleyin.'],
                    ['label' => 'Talep Merkezi', 'description' => 'Yeni modül, limit veya hizmet taleplerinizi kayıt altına alın.'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function securityHighlights(): array
    {
        return [
            'Abone firma verileri birbirinden ayrıdır.',
            'Müşteri ekranlarında maliyet, tedarikçi fiyatı ve teknik Product Data Hub alanları gösterilmez.',
            'Finans ve özel bilgiler yalnız yetkili rollere açılır.',
            'Public takip ve onay bağlantıları güvenli token yapısı ile çalışır.',
            'Ücretsiz deneme ve demo başvuruları otomatik ödeme başlatmaz.',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function demoTopicOptions(): array
    {
        return [
            'Promosyon teklif ve sipariş akışı',
            'Product Data Hub ve tedarikçi ürünleri',
            'Grafik / üretim / fason süreçleri',
            'Müşteri portalı ve teklif onayı',
            'Paketler ve SaaS kiralama modeli',
            'Özel kurulum / kendi sunucuma kurulum görüşmesi',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function publicPackageCards(): array
    {
        return $this->publicPackages()
            ->map(function (Package $package): array {
                return [
                    'package' => $package,
                    'audience' => $this->packageAudience($package),
                    'highlights' => $this->packageHighlights($package),
                    'price_label' => $this->publicPackagePriceLabel($package),
                    'cta_label' => $this->publicPackagePriceLabel($package) ? 'Paketi İncele' : 'Paketi Sor',
                ];
            })
            ->values()
            ->all();
    }

    protected function publicModuleLabel(string $key, string $fallback): string
    {
        return match ($key) {
            'supplier_feed' => 'Tedarikçi Ürün Kaynakları',
            'quote_customer_approval' => 'Müşteri Teklif Onayı',
            'graphic_customer_approval' => 'Grafik Müşteri Onayı',
            'api_access' => 'API Erişimi',
            default => $fallback,
        };
    }

    protected function publicPackagePriceLabel(Package $package): ?string
    {
        $price = $package->monthly_price;

        if ($price === null || (float) $price <= 0) {
            return null;
        }

        return number_format((float) $price, 2, ',', '.') . ' TL';
    }

    protected function packageAudience(Package $package): string
    {
        $key = strtolower((string) $package->key);
        $name = strtolower((string) $package->name);

        return match (true) {
            str_contains($key, 'enterprise'), str_contains($name, 'enterprise') => 'Gelişmiş modül, yüksek limit ve özel ihtiyaçları olan firmalar için uygundur.',
            str_contains($key, 'pro'), str_contains($name, 'pro'), str_contains($name, 'business') => 'Promosyon ve baskı operasyonunu ekipler arası takip etmek isteyen firmalar için uygundur.',
            str_contains($key, 'start'), str_contains($name, 'start'), str_contains($name, 'basic'), str_contains($name, 'starter') => 'İlk kez sistemli teklif ve sipariş takibi kurmak isteyen ekipler için uygundur.',
            default => 'Paket kapsamı ve modül seti başvuru sırasında işletmenizin ihtiyacına göre netleştirilir.',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function packageHighlights(Package $package): array
    {
        $highlights = ['Teklif ve sipariş akışı'];

        if ($package->limitFor('users')) {
            $highlights[] = $package->limitFor('users')?->isUnlimited()
                ? 'Kullanıcı limiti: limitsiz'
                : 'Kullanıcı limiti: ' . $package->limitFor('users')?->limit_value;
        }

        if ($package->limitFor('catalog_products')) {
            $highlights[] = $package->limitFor('catalog_products')?->isUnlimited()
                ? 'Katalog satırı: limitsiz'
                : 'Katalog satırı: ' . $package->limitFor('catalog_products')?->limit_value;
        }

        if ($package->limitFor('orders')) {
            $highlights[] = $package->limitFor('orders')?->isUnlimited()
                ? 'Sipariş akışı: limitsiz'
                : 'Sipariş limiti: ' . $package->limitFor('orders')?->limit_value;
        }

        if ($package->trial_days) {
            $highlights[] = 'Deneme: ' . $package->trial_days . ' gün';
        }

        return array_slice(array_values(array_unique(array_filter($highlights))), 0, 4);
    }
}
