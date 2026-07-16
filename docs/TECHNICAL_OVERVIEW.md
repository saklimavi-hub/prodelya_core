# Prodelya — Genel Teknik Özet

> Son güncelleme: 16 Temmuz 2026
>
> Bu belge, GitHub `main` dalındaki mevcut checkpoint ile proje faz raporları ve son yerel geliştirme durumu birlikte değerlendirilerek hazırlanmıştır. GitHub `main` dalı bazı yerel çalışmalardan geride olabilir; tamamlanmamış veya commit edilmemiş geliştirmeler ilgili bölümlerde ayrıca belirtilmiştir.

---

## 1. Proje Amacı

Prodelya; promosyon, baskı ve üretim sektöründeki firmaların tekliften teslimata kadar olan operasyonlarını tek sistemde yönetmesini amaçlayan çok kiracılı, modüler bir SaaS/ERP platformudur.

Temel süreçler:

- Teklif hazırlama ve müşteriye gönderme
- Teklif onayı, ret ve revizyon talebi
- Teklifin siparişe dönüştürülmesi
- Grafik dosyası ve müşteri grafik onayı
- Tedarikçi ürün, fiyat ve stok verilerinin alınması
- Malzeme tedarik süreci
- İç üretim, dış üretim ve fason üretim takibi
- Teslimat ve koli/etiket süreçleri
- Tahsilat, ödeme ve cari hesap takibi
- Müşteriye özel portal ve süreç görünürlüğü
- Tenant, paket, modül ve abonelik yönetimi

### Temel değer önerisi

Promosyon ve baskı firmalarının dağınık biçimde yürüttüğü müşteri, teklif, ürün, grafik, tedarik, üretim, teslimat ve finans süreçlerini tek bir tenant panelinde birleştirmek.

Prodelya’nın ticari modeli; tenant bazlı çalışan, paket ve modüllerle kiralanabilen veya müşteriye özel kurulabilen bir yazılım hizmetidir.

### Dokümantasyon durumu

Repository kökündeki `README.md` henüz Prodelya’ya özel bir proje açıklaması içermemektedir ve büyük ölçüde Laravel başlangıç metnidir. Gerçek ürün kapsamı faz raporları, servisler, route’lar ve uygulama kodu üzerinden anlaşılmaktadır.

---

## 2. Tech Stack

### Frontend

| Alan | Teknoloji |
|---|---|
| Sunum katmanı | Laravel Blade |
| Diller | HTML, CSS, Vanilla JavaScript |
| Build sistemi | Vite 8 |
| CSS altyapısı | Tailwind CSS 4 ve özel Prodelya admin CSS sistemi |
| Ana admin tasarımı | `public/css/prodelya-admin.css` |
| JavaScript framework | React, Vue veya Angular kullanılmıyor |
| Font yaklaşımı | Vite tarafında Instrument Sans; admin ekranlarında ağırlıklı Arial/Helvetica |

Frontend yapısı klasik server-rendered Blade sayfalarından oluşur. `resources/js/app.js` çok sınırlıdır; uygulama bir SPA değildir.

### Backend

| Alan | Teknoloji |
|---|---|
| Dil | PHP 8.3+ |
| Framework | Laravel 13.8 |
| Mimari | MVC + Service katmanı |
| ORM | Laravel Eloquent |
| Şema yönetimi | Laravel migrations |
| PDF | `barryvdh/laravel-dompdf` |
| QR | `bacon/bacon-qr-code` |
| Queue | Laravel Queue |
| Test | PHPUnit 12 |
| Kod standardı | Laravel Pint |

### Veritabanı

- İlişkisel veritabanı kullanılır.
- ORM olarak Laravel Eloquent kullanılır.
- Geliştirme örnek ortamında SQLite tanımlıdır.
- Testler SQLite `:memory:` üzerinde çalışacak şekilde yapılandırılmıştır.
- MySQL/MariaDB bağlantı alanları `.env.example` içinde hazırdır.
- Canlı ortam için veritabanı sürücüsü deployment aşamasında belirlenmelidir.

### Auth sistemi

Auth JWT tabanlı değildir. Laravel session tabanlı iki guard bulunur:

1. `web`: Super Admin ve tenant panel kullanıcıları
2. `customer_portal`: Müşteri portalı kullanıcıları

Yetkilendirme katmanları:

- Merkezi domain kontrolü
- Tenant çözümleme
- Tenant aktiflik kontrolü
- Tenant üyeliği
- Super Admin kontrolü
- Rol ve permission kontrolü
- Paket/modül kontrolü
- Feature flag kontrolü
- Finansal veriler için ayrı hassas permission kontrolleri

Ana middleware alias’ları:

```text
resolve.tenant
tenant.active
module.enabled
feature.enabled
permission.check
central.access
central.public
super.admin
tenant.membership
customer.portal.auth
```

OAuth, Auth0, Firebase Auth, Passport veya Sanctum tabanlı ana API auth sistemi mevcut bağımlılıklarda görünmemektedir.

### Ödeme ve subscription

Prodelya içinde aşağıdaki SaaS ödeme/abonelik omurgası bulunmaktadır:

- Paketler
- Modüller
- Feature’lar
- Tenant limitleri
- Deneme süresi
- Paket yükseltme talepleri
- Tenant faturalandırma kayıtları
- Ödeme sağlayıcısı yönetimi
- Checkout session kayıtları
- Başarılı, başarısız ve iptal callback’leri
- Webhook logları

Ödeme sürücüleri:

- `iyzico`
- `null` — hazırlık veya placeholder sürücüsü

Iyzico için API istemcisi ve hosted checkout hazırlığı bulunur. Ancak canlı kullanım öncesinde provider-specific signature/header doğrulaması, webhook güvenliği, idempotency ve uçtan uca canlı testler tamamlanmalıdır.

Tenantların kendi müşterilerinden online ödeme alacağı ayrı tenant ödeme modülü henüz tamamlanmış değildir.

### Hosting ve deployment

Bilinen geliştirme ortamı:

- Windows
- Laragon
- Yerel domain/subdomain yapısı
- Git ve GitHub
- Laravel Artisan
- Vite

Planlanan canlı ortam yaklaşımı:

- Linux VPS veya sanal sunucu
- Plesk veya benzeri kontrol paneli
- Git/SSH tabanlı deployment
- Queue worker
- Laravel scheduler/cron
- Wildcard subdomain ve SSL
- Veritabanı ve storage yedekleri

Laravel health endpoint’i `/up` olarak tanımlıdır.

İncelenen checkpoint’te tam üretim hazır:

- Docker imajı
- GitHub Actions CI/CD
- Otomatik deployment
- Otomatik rollback
- Queue restart otomasyonu
- Migration güvenlik kapısı

bulunmamaktadır.

---

## 3. Klasör Yapısı

```text
prodelya_core/
├── app/
│   ├── Console/
│   │   └── Commands/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   ├── SuperAdmin/
│   │   │   ├── CustomerPortal/
│   │   │   ├── Payments/
│   │   │   ├── Auth/
│   │   │   └── Marketing/
│   │   └── Middleware/
│   ├── Models/
│   ├── Services/
│   │   ├── ProductDataHub/
│   │   ├── Procurement/
│   │   ├── Payments/
│   │   ├── Notifications/
│   │   └── SuperAdmin/
│   └── Support/
├── bootstrap/
│   └── app.php
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── docs/
├── public/
│   └── css/
│       └── prodelya-admin.css
├── resources/
│   ├── css/
│   ├── js/
│   └── views/
│       ├── admin/
│       ├── super-admin/
│       ├── customer-portal/
│       └── public/
├── routes/
│   ├── web.php
│   └── console.php
├── storage/
├── tests/
│   ├── Feature/
│   └── Unit/
├── composer.json
├── package.json
├── phpunit.xml
└── vite.config.js
```

---

## 4. Mevcut Özellikler

### SaaS ve tenant altyapısı

- Merkezi Super Admin paneli
- Tenant oluşturma ve düzenleme
- Tenant subdomain alanı
- Özel domain ve portal domain alanları
- Tenant durum yönetimi
- Paket, modül, feature ve limit yönetimi
- Deneme süresi ve subscription durumu
- Tenant sahibi ve kullanıcı oluşturma
- Tenant bazlı rol ve permission altyapısı
- Tenant izolasyonu
- Tenant onboarding/readiness kontrolleri
- Demo ve ücretsiz deneme başvuruları
- Başvuruyu tenant hesabına dönüştürme
- Tenant kullanım limitleri
- Tenant faturalandırma defteri

### Teklif ve sipariş

- Promosyon teklifi oluşturma ve düzenleme
- Matbaa teklif altyapısı
- Teklif kalemleri
- Ürün ve varyant seçimi
- Baskı kalemleri
- Ürün, fiyat ve stok snapshot’ları
- KDV ve toplam hesaplama
- Teklif PDF’i
- WhatsApp teklif bağlantısı
- Public teklif onay linki
- Teklif onaylama
- Teklif reddetme
- Revizyon isteme
- Tekliften sipariş oluşturma
- Promosyon ve matbaa sipariş ailesi
- Sipariş durum ve operasyon takibi
- Finansal veriler için permission tabanlı görünürlük

Teklif ve siparişler aynı `orders` tablosunda `document_type` alanı ile ayrılır. Tekliften oluşan sipariş `source_quote_id` ile kaynak teklife bağlanır.

### Müşteri portalı

- Müşteri kullanıcı daveti
- Müşteri girişi
- Şifre sıfırlama
- Teklif listesi
- Teklif detayı ve onay bağlantısı
- Sipariş listesi
- Sipariş detayı
- İş formu/süreç takibi
- Müşteriye açık dosyalar
- Tenant bazlı portal erişimi

### Firma ve cari hesap

- Firma/cari kart oluşturma
- Müşteri, tedarikçi, fasoncu, üretim partneri ve taşıyıcı rolleri
- Yetkili kişi yönetimi
- Adres yönetimi
- Firma import altyapısı
- Müşteri portal kullanıcısı oluşturma
- Cari hesap bağlantıları
- Borç/alacak hareketleri
- Açılış bakiyesi
- Manuel hareket
- Tahsilat ve ödeme hareketleri
- Hareket iptali
- Cari bakiye ve ekstre ekranları
- Aktif, açık, vadesi geçmiş, tümü ve arşiv filtreleri

### Product Data Hub

- Tedarikçi tanımları
- Tedarikçi kaynak tanımlama
- XML kaynakları
- API kaynakları
- CSV kaynakları
- Excel ve manuel kaynak türü altyapısı
- Kaynak bağlantı testi
- 5–10 ürünlük önizleme/staging yaklaşımı
- Alan eşleme
- Kategori eşleme
- Ham ürün ve varyant kayıtları
- Standart ürün ve varyant oluşturma
- Standart kategori ağacı
- Tenant katalog projeksiyonu
- Tenant bazlı tedarikçi erişimi
- Fiyat/stok değişiklik taraması
- Delta dry-run
- Fiyat/stok uygulama
- Dirty katalog projeksiyonu
- Yeni ve kaybolan ürün review kayıtları
- Katalog görünürlüğü
- Teklif görünürlüğü
- Yerel ürün yönetimi
- Yerel stok yönetimi
- Kategori bakım ve temizleme araçları
- Sync logları ve raporları

Ana veri akışı:

```text
Tedarikçi kaynağı
→ Ham ürün/veri
→ Alan ve kategori eşleme
→ Normalize edilmiş standart ürün
→ Tenant katalog projeksiyonu
→ Teklifte kullanılabilir ürün
```

### Grafik operasyonları

- Grafik iş listesi
- Grafik operasyon durumu
- İş formu ve dosya bağlantıları
- Müşteri grafik onayı
- Public grafik onay linki
- Grafik onayı veya revizyon talebi

### Tedarik

- Sipariş kalemlerinden tedarik ihtiyaçları
- Tedarikçiye göre talep oluşturma
- Taslak durum
- Talep edildi durumu
- Sipariş verildi durumu
- Kısmi geldi durumu
- Tamamlandı durumu
- İptal durumu
- Tedarikçi formu yazdırma
- Alış fiyatı ve iskonto altyapısı
- Tedarik maliyetinden tedarikçi cari borcu üretme

### Üretim

- İç üretim
- Dış üretim
- Fason üretim
- Üretim ataması
- Planlanan miktar
- Tamamlanan miktar
- Kalan miktar
- Kısmi üretim
- Fasona gönderme
- Fasondan dönüş
- Kalite kontrol
- Sorun bildirimi
- Üretimi tamamlama
- Klişe/setup durumları
- Fason maliyet kaydı
- Fason cari borç entegrasyonu
- Üretim event ve bildirim altyapısı

### Teslimat

- Teslimat listesi
- Teslimata hazırlama
- Koli planı altyapısı
- Etiket hazırlama
- Teslimat bilgisi
- Teslim edildi durumu
- Public iş formu takip sayfası

### Finans

- Sipariş finans özeti
- Tahsilat kaydı
- Kısmi ödeme
- Ödeme iptali
- Siparişi ödendi işaretleme
- Müşteri cari hareket senkronizasyonu
- Tedarikçi cari borcu
- Fason cari borcu
- Tenant billing kayıtları
- Checkout session omurgası
- Iyzico ödeme sağlayıcısı altyapısı

### Bildirimler

- Bildirim merkezi
- Bildirim template’leri
- Bildirim logları
- Tenant bildirim ayarları
- E-posta altyapısı
- WhatsApp hazır mesaj/link üretimi
- Teklif, grafik ve üretim eventleri

---

## 5. Eksik veya Yarım Kalan Özellikler

### Güncel F1P3H tedarik fiyat çalışması

Son recovery durumuna göre:

| Konu | Durum |
|---|---|
| Gerçek tedarikçi fiyat kaynağı | Kısmi |
| Orijinal para birimi gösterimi | Kısmi |
| Alış birim fiyatı input bağlantısı | Kısmi |
| Eski taslak fiyatını yenileme | Başlanmadı |
| İptal edilen tedarik talebini geri alma | Başlanmadı |
| Hedefli testler | Kısmi |
| Geniş Procurement testleri | Başarısız |
| ProcessDepth testleri | Başarılı |
| Staging/commit | Yok |

Tamamlanması gereken ana maddeler:

- Canonical supplier purchase source doğrulaması
- Orijinal TRY/USD/EUR fiyatının gösterilmesi
- Kaynak kur ve TL karşılığının gösterilmesi
- Alış iskontosu
- Düzenlenebilir alış birim fiyatı
- “Hesaplananı kullan” geri yükleme davranışı
- Taslak tedarik talebinde fiyatları yenileme
- Snapshot metadata sertleştirmesi
- İptal edilen tedarik talebi için güvenli “İptali Geri Al” akışı

### Sipariş revizyon sistemi

Siparişe dönüşen belgelerde geçmişi bozmadan:

- Revize 1
- Revize 2
- Önceki sürümü koruma
- Revizyonlar arası karşılaştırma
- Revize siparişin operasyon kayıtlarıyla ilişkisi

henüz yeniden uygulanmamıştır.

### Aktif ve tamamlanmış liste ayrımı

Tamamlanması gerekenler:

- Siparişe dönüşmüş teklifleri aktif teklif listesinden çıkarmak
- Tamamlanan siparişleri ayrı sekmeye almak
- Operasyon tamamlanınca siparişin otomatik tamamlanma kuralı
- Finans takibi devam ederken operasyonel listeden ayrılması
- En yeni kayıtları üstte gösterme standardı

### Gelişmiş matbaa ve maliyet motoru

Planlanan fakat tam uygulanmayan alanlar:

- Malzeme fiyat kütüphanesi
- Üretim işlem fiyatları
- Hazırlık elemanları
- Bıçak, klişe, kalıp ve film arşivi
- Kağıt gramajı ve tabaka hesabı
- Fire ve montaj hesabı
- Gerçek maliyet fark analizi
- Karlılık uyarıları
- Kapasite ve termin planlama
- Dieline ve nesting entegrasyonu
- Alternatif adetli tekliflerin tam maliyet snapshot’ı
- Matbaa ürün reçeteleri

### Product Data Hub

Tamamlanması veya sadeleştirilmesi gerekenler:

- Karmaşık ekranların sadeleştirilmesi
- Yeni/kaybolan ürün review kayıtlarına gerçek aksiyonların bağlanması
- Supplier kaynaklarında canlı veri doğrulaması
- Export servislerinin tam operasyonel hale gelmesi
- XML/JSON/CSV/API çıkışlarının tenantlara açılması
- Uzun süren sync işlerinin queue/job tabanlı çalışması
- Alan ve kategori eşleme akışının sadeleştirilmesi
- Eski ve yeni fiyat/stok alanlarının canonical hale getirilmesi

### Ödeme

- Iyzico canlı signature/header sözleşmesi
- Webhook signature doğrulaması
- Webhook retry ve idempotency
- Tenant müşterilerinden ödeme alma modülü
- Subscription otomatik yenileme
- Ödeme credential şifrelemesi
- Canlı sandbox/production testleri

### Operasyon ve sistem yönetimi

- Queue retry ve failed job ekranı
- Gerçek backup sağlık kontrolü
- Scheduler sağlık doğrulaması
- Disk kullanım ölçümü
- WhatsApp Business API
- SMS entegrasyonu
- Gelişmiş raporlama merkezi
- API token yönetimi
- Otomatik deployment ve rollback

---

## 6. Veritabanı Şeması

Şema geniştir. Aşağıda ana model grupları ve ilişkileri yer alır.

### Tenant ve kullanıcı

```text
TenantAccount
├── Package
├── TenantModule
├── TenantSetting
├── UserRole
│   ├── User
│   └── Role
├── Company
├── CurrentAccount
├── Order
├── TenantCatalogProduct
├── TenantSupplierAccess
├── NotificationTemplate
├── NotificationLog
├── TenantBillingEntry
├── PaymentCheckoutSession
├── PaymentGatewayCredential
└── AuditLog
```

`TenantAccount`, sistemdeki ana veri izolasyon sınırıdır. Tenant verilerinin çoğunda `tenant_account_id` bulunur.

### Firma ve cari yapı

```text
Company
├── CompanyContact
├── CompanyAddress
├── CompanyRole
├── CustomerPortalUser
├── Customer Orders
└── CurrentAccountLink
        └── CurrentAccount
            ├── CurrentAccountRole
            ├── CurrentAccountLink
            └── CurrentAccountTransaction
```

Ana kavramlar:

- `Company`: ticari kimlik, iletişim, adres ve rol kartı
- `CurrentAccount`: finansal borç/alacak defteri
- `CurrentAccountLink`: Company, Supplier veya başka kaynakları cari hesaba bağlar
- `CurrentAccountTransaction`: borç, alacak, tahsilat, ödeme, açılış ve düzeltme hareketleri

Cari hareketler `source_type` ve `source_id` alanlarıyla sipariş, ödeme, tedarik veya fason kaynağına bağlanabilir.

### Teklif ve sipariş

```text
Order
├── document_type: quote | order
├── sourceQuote
├── convertedOrders
├── OrderItem
│   ├── OrderItemPrint
│   ├── OrderItemWorkForm
│   ├── OrderItemProcurement
│   ├── OrderItemPrintGraphic
│   ├── OrderItemPrintProduction
│   ├── OrderItemWorkFormDelivery
│   └── OrderItemWorkFolder
├── QuoteSendSnapshot
├── QuoteApprovalRequest
├── GraphicApprovalRequest
└── OrderPayment
```

`OrderItem` aşağıdaki snapshot ve bağlantıları taşıyabilir:

- Tenant katalog ürünü
- Tenant katalog varyantı
- Standart ürün
- Standart ürün varyantı
- Supplier ve supplier source
- Ürün snapshot’ı
- Fiyat snapshot’ı
- Stok snapshot’ı
- Baskı satırları

### Tedarik

```text
Supplier
└── SupplierProcurementRequest
    └── SupplierProcurementRequestItem
        ├── Order bağlantısı
        ├── OrderItem bağlantısı
        ├── Fiyat snapshot’ı
        ├── Gelen miktar
        └── CurrentAccountTransaction kaynağı
```

Tedarik talebi durumları:

```text
taslak
talep_edildi
siparis_verildi
kismi_geldi
tamamlandi
iptal
```

### Üretim

```text
OrderItemPrintProduction
├── TenantAccount
├── Order
├── OrderItem
├── OrderItemPrint
├── OrderItemWorkForm
├── Production Company
├── Assigned User
├── Graphic Operation
└── Subcontractor CurrentAccountTransaction
```

Üretim türleri:

```text
internal
external
outsourced
```

Üretim; planlanan miktar, tamamlanan miktar, kalan miktar, kalite kontrol, klişe durumu ve fason maliyetini saklar.

### Product Data Hub

```text
Supplier
└── SupplierSource
    ├── SupplierProductRaw
    ├── SupplierProductVariantRaw
    ├── SupplierFieldMapping
    ├── SupplierCategoryMapping
    ├── FeedSyncLog
    └── ProductDataHubSyncRun
            ↓
      StandardProduct
      ├── StandardProductVariant
      ├── StandardProductImage
      └── StandardCategory
            ↓
      TenantCatalogProduct
      ├── TenantCatalogProductVariant
      ├── TenantCatalogProductImage
      ├── TenantLocalStock
      └── ProductPriceSnapshot
```

### Ödeme ve SaaS faturalandırma

```text
PaymentProvider
├── PaymentGatewayCredential
├── PaymentCheckoutSession
└── PaymentWebhookLog

TenantAccount
└── TenantBillingEntry
    └── PaymentCheckoutSession
```

Ödeme credential kapsamları:

- `super_admin_shared`
- `tenant_module`

---

## 7. Bilinen Sorunlar ve Teknik Borçlar

### README güncel değil

README, Prodelya’nın kurulumunu, modüllerini, tenant yapısını ve deployment sürecini açıklamamaktadır.

### GitHub ve yerel çalışma ağacı eşit değil

GitHub `main` dalı bazı yerel geliştirmelerden geridedir. Yerelde uygulanmış ancak staging/commit yapılmamış değişiklikler bulunabilir.

Riskler:

- Kod kaybı
- Fazların birbirine karışması
- Başka bilgisayarda eksik kod
- Güvenli rollback zorluğu
- CI tarafından doğrulanmayan değişiklikler

### Procurement geniş testleri yeşil değil

Son recovery audit’e göre hedefli testlerin bazıları geçse de geniş Procurement test grubu başarısızdır. Yerel çalışma ağacı için tüm testler geçiyor denemez.

### Tedarikçi fiyat zinciri tamamlanmadı

Belirsiz veya kısmi alanlar:

- Gerçek supplier source fiyatı
- Orijinal para birimi
- Kaynak kur
- TL karşılığı
- Düzenlenebilir alış birim fiyatı
- Taslak fiyat yenileme
- Snapshot kaynağının ekranda gösterilmesi

### İptal edilen tedarik talebi geri alınamıyor

Gerekli fakat tamamlanmamış parçalar:

- Route
- Controller/service
- Yetki kontrolü
- Gelen miktar guard’ı
- Cari hareket guard’ı
- Audit log
- Idempotency
- UI butonu
- Feature testler

### Ödeme credential güvenliği

Ödeme credential’ları JSON alanlarında tutulmaktadır. Uygulama seviyesinde encrypted cast veya ayrı secret vault yaklaşımı uygulanmalıdır.

### Product Data Hub geçiş şeması karmaşık

`StandardProduct` ve `TenantCatalogProduct` üzerinde eski ve yeni isim, fiyat, stok ve kategori alanları birlikte bulunur. Bu durum canonical veri kaynağı belirsizliği ve bakım maliyeti oluşturur.

### Supplier ilişkisinde legacy belirsizlik

`OrderItem.supplier_id` hem `Supplier` hem de legacy `Company` ilişkisiyle yorumlanabilmektedir. İleride tek canonical ilişkiye indirgenmelidir.

### Teklif ve sipariş aynı tabloda

Bu yaklaşım dönüşümü kolaylaştırır ancak aşağıdaki alanları karmaşıklaştırır:

- Durum yönetimi
- Aktif/tamamlanan listeler
- Revizyonlar
- Düzenleme kilitleri
- Finans ve operasyon ayrımı

### Eski TODO yorumları

Bazı modellerde uygulanmış ilişkiler için hâlâ “sonra eklenecek” yorumları bulunur. TODO’lar temizlenmeli veya gerçek teknik borcu gösterecek şekilde güncellenmelidir.

### Deployment otomasyonu eksik

Henüz doğrulanmış bir:

- CI workflow
- Otomatik deployment
- Migration güvenlik kapısı
- Bakım modu akışı
- Queue restart
- Scheduler kontrolü
- Otomatik rollback

bulunmamaktadır.

---

## 8. Ortam Değişkenleri

Aşağıdaki listede yalnız değişken isimleri yer alır.

### Uygulama

```text
APP_NAME
APP_ENV
APP_KEY
APP_DEBUG
APP_URL
APP_LOCALE
APP_FALLBACK_LOCALE
APP_FAKER_LOCALE
APP_MAINTENANCE_DRIVER
APP_MAINTENANCE_STORE
PHP_CLI_SERVER_WORKERS
BCRYPT_ROUNDS
```

### Log

```text
LOG_CHANNEL
LOG_STACK
LOG_DEPRECATIONS_CHANNEL
LOG_LEVEL
```

### Veritabanı

```text
DB_CONNECTION
DB_URL
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
```

### Session

```text
SESSION_DRIVER
SESSION_LIFETIME
SESSION_ENCRYPT
SESSION_PATH
SESSION_DOMAIN
```

### Queue, cache, filesystem ve broadcasting

```text
BROADCAST_CONNECTION
FILESYSTEM_DISK
QUEUE_CONNECTION
CACHE_STORE
CACHE_PREFIX
```

### Redis ve Memcached

```text
MEMCACHED_HOST
REDIS_CLIENT
REDIS_HOST
REDIS_PASSWORD
REDIS_PORT
```

### E-posta

```text
MAIL_MAILER
MAIL_SCHEME
MAIL_HOST
MAIL_PORT
MAIL_USERNAME
MAIL_PASSWORD
MAIL_FROM_ADDRESS
MAIL_FROM_NAME
```

### AWS / S3 uyumlu depolama

```text
AWS_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY
AWS_DEFAULT_REGION
AWS_BUCKET
AWS_USE_PATH_STYLE_ENDPOINT
```

### Frontend/Vite

```text
VITE_APP_NAME
```

### Auth config tarafından desteklenen değişkenler

```text
AUTH_GUARD
AUTH_PASSWORD_BROKER
AUTH_MODEL
AUTH_PASSWORD_RESET_TOKEN_TABLE
AUTH_PASSWORD_TIMEOUT
```

### Test ve gözlem araçları

```text
PULSE_ENABLED
TELESCOPE_ENABLED
NIGHTWATCH_ENABLED
```

### Ödeme servisleri hakkında not

Mevcut `.env.example` içinde doğrudan aşağıdaki gibi Iyzico değişkenleri bulunmamaktadır:

```text
IYZICO_API_KEY
IYZICO_SECRET_KEY
IYZICO_MERCHANT_KEY
```

Ödeme sağlayıcısı credential’ları Super Admin paneli üzerinden `PaymentGatewayCredential` kayıtlarına yazılmaktadır.

---

## Genel Teknik Değerlendirme

Prodelya artık basit bir sipariş takip uygulaması değildir. Mevcut yapı aşağıdaki katmanları tek SaaS çekirdeğinde birleştirmektedir:

```text
Tenant ve paket yönetimi
+ Firma ve cari hesap
+ Teklif ve sipariş
+ Müşteri onayları
+ Grafik
+ Tedarik
+ İç/dış/fason üretim
+ Teslimat
+ Finans
+ Product Data Hub
+ Müşteri portalı
+ SaaS billing
```

Güçlü taraflar:

- Sektör odaklı operasyon akışları
- Tenant izolasyonu
- Rol ve permission sistemi
- Tekliften teslimata takip
- Müşteri onay sistemleri
- Tedarikçi ürün/fiyat/stok havuzu
- Cari hareket otomasyonları
- Modüler SaaS yaklaşımı

Canlıya çıkış öncesi öncelikli teknik kapılar:

1. Yerel değişiklikleri güvenli commit/checkpoint altına almak
2. Procurement fiyat zincirini tamamlamak
3. Geniş test grubunu tekrar yeşile getirmek
4. Payment credential şifrelemesini uygulamak
5. CI/CD ve deployment prosedürünü kurmak
6. Product Data Hub’ı sadeleştirmek
7. Sipariş revizyon ve tamamlanmış liste kurallarını tamamlamak
8. Backup, scheduler ve queue operasyonlarını doğrulamak
