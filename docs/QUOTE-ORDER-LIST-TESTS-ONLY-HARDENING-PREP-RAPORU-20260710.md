# Quote / Order List Tests-Only Hardening Prep Raporu — 2026-07-10

## 1. Faz Özeti

- Faz türü: read-only test audit
- Uygulama kodu değiştirildi mi: hayır
- Test dosyaları değiştirildi mi: hayır
- Dosya silindi mi: hayır
- Staging yapıldı mı: hayır
- Commit yapıldı mı: hayır
- Bu fazda yalnız analiz, production karşılığı doğrulaması ve tests-only checkpoint uygunluk kararı üretildi.

## 2. Ground Truth

### 2.1 Başlangıç git durumu

- `git diff --cached --stat`: boş
- `git diff --cached --name-status`: boş
- `git diff --stat`: yalnız önceden mevcut worktree değişiklikleri var
- `git diff --name-status`: yalnız önceden mevcut worktree değişiklikleri var
- Modified hedef testler:
  - `tests/Feature/PromotionQuoteAndOrderIndexHeaderPanelTest.php`
  - `tests/Feature/PromotionQuoteAndOrderIndexUxTest.php`
- Untracked hedef testler:
  - `tests/Feature/QuoteOrderListNoSensitiveLeakTest.php`
  - `tests/Feature/QuoteOrderListNoTechnicalUiLeakRegressionTest.php`
  - `tests/Feature/QuoteOrderListTenantIsolationTest.php`
  - `tests/Feature/QuoteOrderListTurkishTerminologyTest.php`
  - `tests/Feature/QuoteOrderManualSmokeRouteTest.php`

### 2.2 Modified test diff özeti

- `PromotionQuoteAndOrderIndexHeaderPanelTest.php`
  - `Açık Siparişler` → `Aktif Siparişler`
  - `Müşteri Onayı Bekleyenler` → `Açık Teklifler`
  - `Siparişe Çevrilebilir` → `Siparişe Dönüşenler`
- `PromotionQuoteAndOrderIndexUxTest.php`
  - `Hazırlanan Teklifler` → `Açık Teklifler`

## 3. İncelenen Production Karşılıkları

### 3.1 Teklif listesi

- `resources/views/admin/promotion-quotes/index.blade.php`
  - başlık: `Promosyon Teklifleri`
  - aksiyonlar: `Yeni Promosyon Teklifi`, `Açık Teklifler`, `Siparişe Dönüşenler`
  - özet kartları: `Açık Teklifler`, `Müşteri Onayı Bekleyen`, `Revize İstenen`, `Siparişe Dönüşenler`, `Arşiv`, `Onaylananlar`
  - tablo başlığı: `Teklif Listesi`
  - sekmeler: `Açık Teklifler`, `Siparişe Dönüşenler`, `Arşiv`, `Tümü`
  - toplam kolonu permission tabanlı
  - teknik alanlar template içinde render edilmiyor

### 3.2 Sipariş listesi

- `resources/views/admin/orders/index.blade.php`
  - üst aksiyonlar: `Tüm Siparişler`, `Aktif Siparişler`, `Tamamlanan Siparişler`
  - kartlar: `Açık Sipariş`, `Grafik Bekleyen`, `Tedarik Bekleyen`, `Üretim Bekleyen / Bloklu`, `Teslimat Bekleyen`
  - filtre sekmeleri: `Aktif Siparişler`, `Tamamlanan Siparişler`, `Tümü`, `Operasyonda`, `Teslimat Bekleyen`
  - finance izni varsa ek sekme: `Ödeme Bekleyen`
  - tablo başlığı: `Sipariş Listesi`
  - kolonlar arasında `Sıradaki İş` ve finance kolonları permission tabanlı
  - sağ panel: `Seçili Sipariş`, `Hızlı Geçişler`, `Süreç Durumu`, finance yetkisi varsa `Finans Özeti`

### 3.3 Controller ve service doğrulaması

- `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - `filter/view` değerleri: `active`, `converted`, `archived`, `all`
  - active görünüm `activeQuotes()` scope’unu kullanıyor
  - converted görünüm `convertedQuotes()` scope’unu kullanıyor
  - archived görünüm `archivedQuotes()` scope’unu kullanıyor
  - liste `latest()` ile sıralanıyor
- `app/Models/Order.php`
  - `activeQuotes()` scope’u dönüştürülen teklifleri ve archived approval durumlarını hariç tutuyor
  - `convertedQuotes()` scope’u workflow veya bağlı order ilişkisinden çalışıyor
  - `archivedQuotes()` scope’u cancelled/rejected/expired kayıtları kapsıyor
- `app/Http/Controllers/Admin/OrderController.php`
  - `filter/status` değerleri: `open`, `completed`, `all`, `in_operation`, `delivery_pending`, `payment_pending`, `problem`
  - finance yetkisi yoksa `payment_pending` zorla `open`a düşürülüyor
  - query `orders()` scope’u ve tenant filtresi ile geliyor
  - liste `orderByDesc('created_at')`
- `app/Services/OrderListSummaryService.php`
  - `open`: `!is_cancelled && !is_completed`
  - `completed`: `is_completed`
  - `payment_pending`: yalnız finance yetkisi varsa
  - sticky panel ve row payload finance alanlarını sadece yetkili kullanıcıya ekliyor

### 3.4 Route doğrulaması

- `routes/web.php`
  - `admin.promotion-quotes.index`
  - `admin.orders.index`
  - `admin.orders.convert.from.quote`
  - `admin.finance.show`

## 4. Test Master Envanteri

| Test | Durum | Amaç | Production Karşılığı | Karar |
|---|---|---|---|---|
| `PromotionQuoteAndOrderIndexHeaderPanelTest` | Modified | header/panel metinlerini ve güvenli liste yüzeyini kilitlemek | quote/order index Blade metinleri + selected order panel | B |
| `PromotionQuoteAndOrderIndexUxTest` | Modified | quote/order liste UX, permission ve operasyon görünümünü kilitlemek | quote/order index Blade + OrderListSummaryService + permission akışı | A |
| `QuoteOrderListNoSensitiveLeakTest` | Untracked | liste ekranlarında hassas alan isimleri sızmıyor mu kontrolü | her iki index Blade + response HTML | A |
| `QuoteOrderListNoTechnicalUiLeakRegressionTest` | Untracked | tüm quote/order filtre sekmelerinde teknik snapshot alanları sızmıyor mu kontrolü | quote/order filters + Blade render yüzeyi | A |
| `QuoteOrderListTenantIsolationTest` | Untracked | iki tenant arası liste izolasyonu | her iki controller query tenant filtresi | A |
| `QuoteOrderListTurkishTerminologyTest` | Untracked | güncel Türkçe etiketleri ve bozuk ASCII varyantlarının görünmemesini kontrol etmek | quote/order index metinleri ve filter uyumluluğu | B |
| `QuoteOrderManualSmokeRouteTest` | Untracked | liste route’larının manuel smoke amacıyla açıldığını doğrulamak | aynı route’lar | D |

## 5. Assertion / Production Eşleme

| Test | Assertion | Blade/Service/Route Karşılığı | Uyumlu mu |
|---|---|---|---|
| HeaderPanel | `Aktif Siparişler` | `resources/views/admin/orders/index.blade.php` üst buton + tab label | Evet |
| HeaderPanel | `Seçili Sipariş` | orders sticky panel | Evet |
| HeaderPanel | `Açık Teklifler` | quote page action ve summary label | Evet |
| HeaderPanel | `Siparişe Dönüşenler` | quote page action ve chip label | Evet |
| HeaderPanel | `Onay Bekliyor` | quote approval badge | Evet |
| UxTest | `Açık Teklifler` | quote summary/action label | Evet |
| UxTest | `Müşteri Onayı Bekleyen` | quote summary card | Evet |
| UxTest | `Onaylananlar` | quote summary card | Evet |
| UxTest | `Teklif Listesi` | quote table header | Evet |
| UxTest | convert action testid | quote action button condition | Evet |
| UxTest | operations user toplam görmez | `canViewFinancialData` koşullu total kolonu | Evet |
| UxTest | `Açık Sipariş` | order summary card / row status | Evet |
| UxTest | `Grafik Bekleyen` / `Tedarik Bekleyen` / `Üretim Bekleyen / Bloklu` / `Teslimat Bekleyen` | order summary cards | Evet |
| UxTest | `Sipariş Listesi` | orders table header | Evet |
| UxTest | `Grafik:` `Tedarik:` `Üretim:` `Teslimat:` | row module badges | Evet |
| UxTest | operations user finance link görmez | service sticky panel finance alanını yetkisiz kullanıcıya eklemiyor | Evet |
| NoSensitiveLeak | `supplier_cost`, `purchase_price`, `profit`, `meta_json`, `current_account_id` görünmez | index Blade’ler bu alanları render etmiyor | Evet |
| NoTechnicalUiLeak | `group_code`, `file_path`, `transaction_id`, `price_snapshot`, `projection`, `raw` görünmez | filter sekmelerinde response HTML | Evet |
| TenantIsolation | local görünür, foreign görünmez | tenant scoped query in both controllers | Evet |
| TurkishTerminology | `Siparişe Dönüşenler`, `Açık Teklifler`, `Aktif Siparişler`, `Tamamlanan Siparişler`, `Sıradaki İş` | blade labels | Evet |
| TurkishTerminology | `Siparis`, `Donusen`, `Arsiv`, `Tamamlanmis`, `Guncel` görünmez | kullanıcı yüzeyi metinleri | Evet |
| ManualSmoke | 6 route `assertOk()` | route/controller render zinciri | Evet, ama çok zayıf |

## 6. Duplicate / Örtüşme Matrisi

| Test A | Test B | Örtüşme | Karar |
|---|---|---|---|
| `PromotionQuoteAndOrderIndexHeaderPanelTest` | `PromotionQuoteAndOrderIndexUxTest` | quote/order liste metinleri ve teknik alan görünmezliği kısmen ortak | Tamamlayıcı, ileride birleştirilebilir |
| `PromotionQuoteAndOrderIndexHeaderPanelTest` | `OrderIndexRealListTest` | selected panel, next action, safe fields tarafında kısmi ortaklık | Tamamlayıcı |
| `PromotionQuoteAndOrderIndexUxTest` | `OrderIndexRealListTest` | order liste UX ve finance permission assertion’ları kısmen ortak | Tamamlayıcı, tam duplicate değil |
| `PromotionQuoteAndOrderIndexUxTest` | `PromotionQuoteSalesStartScreenTest` | quote liste terminolojisi ve tab sayaçları kısmen ortak | Tamamlayıcı |
| `QuoteOrderListNoSensitiveLeakTest` | `QuoteOrderListNoTechnicalUiLeakRegressionTest` | bazı forbidden string’ler ortak | Tamamlayıcı, farklı güvenlik katmanı |
| `QuoteOrderListNoTechnicalUiLeakRegressionTest` | `QuoteOrderManualSmokeRouteTest` | aynı 6 route açılıyor | Manual smoke gereksiz tekrar |
| `QuoteOrderListTurkishTerminologyTest` | `ActiveQuotesHideConvertedOrdersTest` / `ActiveOrdersHideCompletedOrdersTest` / `CompletedOrdersListTest` / `ConvertedQuotesListTest` | label ve filtre başlıkları örtüşüyor | Değerli ama konsolide edilebilir |
| `QuoteOrderManualSmokeRouteTest` | `AdminSmokeTest` | base routes 200 smoke kısmen ortak | Gereksiz tekrar |
| `QuoteOrderManualSmokeRouteTest` | `QuoteOrderListNoTechnicalUiLeakRegressionTest` | aynı URL matrisi + daha zayıf assertion | Do not commit |

## 7. Test Çalışma Sonuçları

| Test | Sonuç | Assertion | Süre | Not |
|---|---|---:|---:|---|
| `PromotionQuoteAndOrderIndexHeaderPanelTest` | Passed | 26 | 4759 ms | 2 test geçti |
| `PromotionQuoteAndOrderIndexUxTest` | Passed | 48 | 5533 ms | 4 test geçti |
| `QuoteOrderListNoSensitiveLeakTest` | Passed | 18 | 4675 ms | tek test |
| `QuoteOrderListNoTechnicalUiLeakRegressionTest` | Passed | 42 | 5686 ms | tek test |
| `QuoteOrderListTenantIsolationTest` | Passed | 6 | 4650 ms | tek test |
| `QuoteOrderListTurkishTerminologyTest` | Passed | 13 | 4922 ms | tek test |
| `QuoteOrderManualSmokeRouteTest` | Passed | 6 | 5412 ms | tek test |
| `PromotionQuoteAndOrderIndex|QuoteOrderList|QuoteOrderManualSmoke` matris koşusu | Passed | 159 | 4673 ms | 11 test birlikte geçti |

## 8. Güvenlik Kapsamı

| Test | Tenant | Permission | Finans | Teknik Sızıntı | Terminoloji |
|---|---|---|---|---|---|
| `PromotionQuoteAndOrderIndexHeaderPanelTest` | Dolaylı | Kısmi | Kısmi | Evet | Evet |
| `PromotionQuoteAndOrderIndexUxTest` | Dolaylı | Evet | Evet | Evet | Evet |
| `QuoteOrderListNoSensitiveLeakTest` | Dolaylı | Hayır | Evet | Evet | Hayır |
| `QuoteOrderListNoTechnicalUiLeakRegressionTest` | Dolaylı | Hayır | Dolaylı | Evet | Hayır |
| `QuoteOrderListTenantIsolationTest` | Evet | Hayır | Hayır | Hayır | Hayır |
| `QuoteOrderListTurkishTerminologyTest` | Dolaylı | Hayır | Hayır | Hayır | Evet |
| `QuoteOrderManualSmokeRouteTest` | Dolaylı | Hayır | Hayır | Hayır | Hayır |

## 9. Test Bazlı İnceleme

### 9.1 `PromotionQuoteAndOrderIndexHeaderPanelTest`

- Amaç: header sadeleşmesini, selected order panelini ve quote list unified actions metinlerini kilitlemek.
- Route:
  - `admin.orders.index?selected_order_id=...`
  - `admin.promotion-quotes.index`
- Kullanıcı/rol: seeded admin
- Fixture/veri: bir quote, bir order, order operasyon ilişkileri, quote approval modülü
- Production karşılığı:
  - `Aktif Siparişler`, `Seçili Sipariş`, `Açık Teklifler`, `Siparişe Dönüşenler` doğrudan Blade’de var
- Assertion türleri:
  - başlık/label doğrulaması
  - eski kopya metinlerin görünmemesi
  - selected panel görünürlüğü
  - teknik alan görünmezliği
- Tenant isolation etkisi: dolaylı, fixture aynı tenantta
- Permission etkisi: admin bazlı
- Finans/maliyet görünürlüğü etkisi: dolaylı
- Teknik alan sızıntısı koruması: var
- Türkçe terminoloji koruması: var
- Örtüşme: `PromotionQuoteAndOrderIndexUxTest` ve `OrderIndexRealListTest` ile kısmi ortak
- Duplicate riski: orta
- Flaky riski: düşük
- Database/global state riski: düşük, `RefreshDatabase`
- Test süresi: 4759 ms sınıf toplamı
- Tests-only checkpoint uygunluğu: uygun
- Önerilen karar: `B — KEEP BUT MERGE/CONSOLIDATE LATER`
- Gerekçe: değerli, fakat bazı metin/UX assertion’ları daha geniş testlerle örtüşüyor.

### 9.2 `PromotionQuoteAndOrderIndexUxTest`

- Amaç: quote/order list UX, permission ve operasyon yüzeyini daha geniş kapsamda kilitlemek.
- Route:
  - `admin.promotion-quotes.index`
  - `admin.orders.index?selected_order_id=...`
- Kullanıcı/rol:
  - admin
  - graphic role user
- Fixture/veri:
  - waiting/approved quote
  - active order
  - partial payment order
- Production karşılığı:
  - quote summary cards ve order summary cards birebir Blade’de mevcut
  - finance görünürlüğü service/controller permission akışıyla uyumlu
- Assertion türleri:
  - UI labels
  - convert CTA testid
  - finance visibility hiding
  - operation badges
  - forbidden text
- Tenant isolation etkisi: dolaylı
- Permission etkisi: güçlü
- Finans/maliyet görünürlüğü etkisi: güçlü
- Teknik alan sızıntısı koruması: var
- Türkçe terminoloji koruması: var
- Örtüşme: `OrderIndexRealListTest` ile finance/order tarafında kısmi ortak
- Duplicate riski: düşük-orta
- Flaky riski: düşük
- Database/global state riski: düşük
- Test süresi: 5533 ms sınıf toplamı
- Tests-only checkpoint uygunluğu: uygun
- Önerilen karar: `A — KEEP AND COMMIT`
- Gerekçe: modified diff yalnız label değil; permission ve gerçek operasyon akışını da kilitliyor.

### 9.3 `QuoteOrderListNoSensitiveLeakTest`

- Amaç: liste ekranlarında hassas alan anahtarlarının kullanıcı HTML’ine sızmamasını doğrulamak.
- Route:
  - `admin.promotion-quotes.index`
  - `admin.orders.index`
- Kullanıcı/rol: admin
- Fixture/veri: bir quote, bir order
- Production karşılığı:
  - index Blade’ler bu alanları göstermiyor
  - concern fixture bilinçli olarak snapshot içine hidden alanlar koyuyor
- Assertion türleri: HTML string negative assertions
- Tenant isolation etkisi: yok
- Permission etkisi: yok
- Finans/maliyet görünürlüğü etkisi: evet
- Teknik alan sızıntısı koruması: evet
- Türkçe terminoloji koruması: hayır
- Örtüşme: `NoTechnicalUiLeakRegressionTest` ile kısmi
- Duplicate riski: düşük
- False positive/negative riski:
  - risk orta-düşük
  - yalnız string araması yaptığı için bağlamsız bir debug/help texti de fail ettirebilir
  - buna rağmen bu alanlar kullanıcı yüzeyinde hiç görünmemesi gereken anahtarlar olduğu için değerli
- Flaky riski: düşük
- Database/global state riski: düşük
- Test süresi: 4675 ms
- Tests-only checkpoint uygunluğu: uygun
- Önerilen karar: `A — KEEP AND COMMIT`

### 9.4 `QuoteOrderListNoTechnicalUiLeakRegressionTest`

- Amaç: active/converted/archived/completed/all sekmelerinde teknik snapshot alanlarının sızmadığını garanti etmek.
- Route:
  - quote: `active`, `converted`, `archived`
  - order: `open`, `completed`, `all`
- Kullanıcı/rol: admin
- Fixture/veri:
  - active quote
  - converted quote + order
  - archived quote
  - active order
  - completed order
- Production karşılığı:
  - controller filter/view kombinasyonları ve scope’lar mevcut
  - Blade bu alanları hiçbir görünümde render etmiyor
- Assertion türleri:
  - route matrix
  - `assertOk`
  - forbidden text negative assertions
- Tenant isolation etkisi: yok
- Permission etkisi: yok
- Finans/maliyet görünürlüğü etkisi: dolaylı
- Teknik alan sızıntısı koruması: çok güçlü
- Türkçe terminoloji koruması: yok
- Örtüşme: manual smoke route testini büyük ölçüde subsume ediyor
- Duplicate riski: düşük
- False positive/negative riski:
  - `raw` ve `projection` genel kelimeler olduğu için teorik false positive riski var
  - mevcut liste sayfalarında bağlamsız kullanım gözlenmedi
- Flaky riski: düşük
- Database/global state riski: düşük
- Test süresi: 5686 ms
- Tests-only checkpoint uygunluğu: uygun
- Önerilen karar: `A — KEEP AND COMMIT`

### 9.5 `QuoteOrderListTenantIsolationTest`

- Amaç: quote/order liste ekranlarının tenant scope dışına taşmamasını kilitlemek.
- Route:
  - `admin.promotion-quotes.index`
  - `admin.orders.index`
- Kullanıcı/rol: seeded admin
- Fixture/veri:
  - local quote/order
  - foreign tenant quote/order
- Production karşılığı:
  - her iki controller query’si `tenant_account_id = current tenant`
- Assertion türleri:
  - positive local visibility
  - negative foreign visibility
- Tenant isolation etkisi: doğrudan
- Permission etkisi: hayır
- Finans/maliyet görünürlüğü etkisi: hayır
- Teknik alan sızıntısı koruması: hayır
- Türkçe terminoloji koruması: hayır
- Örtüşme: genel repo tenant testleriyle tema ortak, ama bu iki liste route’u için doğrudan özgün
- Duplicate riski: düşük
- Flaky riski: düşük
- Database/global state riski: düşük
- Test süresi: 4650 ms
- Tests-only checkpoint uygunluğu: uygun
- Önerilen karar: `A — KEEP AND COMMIT`

### 9.6 `QuoteOrderListTurkishTerminologyTest`

- Amaç: quote/order list ekranlarındaki güncel Türkçe etiketleri ve bozuk ASCII varyantlarının görünmemesini kilitlemek.
- Route:
  - `admin.promotion-quotes.index?view=converted`
  - `admin.orders.index?status=completed`
- Kullanıcı/rol: admin
- Fixture/veri:
  - active quote
  - converted quote
  - completed order
- Production karşılığı:
  - controller `view/status` parametrelerini kabul ediyor
  - blade metinleri beklenen Türkçe etiketlerle eşleşiyor
- Assertion türleri:
  - label presence
  - malformed label absence
- Tenant isolation etkisi: dolaylı
- Permission etkisi: hayır
- Finans/maliyet görünürlüğü etkisi: hayır
- Teknik alan sızıntısı koruması: hayır
- Türkçe terminoloji koruması: güçlü
- Örtüşme:
  - `ActiveQuotesHideConvertedOrdersTest`
  - `ConvertedQuotesListTest`
  - `ActiveOrdersHideCompletedOrdersTest`
  - `CompletedOrdersListTest`
  - modified header/ux testleri
- Duplicate riski: orta
- Flaky riski: orta-düşük
- Database/global state riski: düşük
- Test süresi: 4922 ms
- Tests-only checkpoint uygunluğu: uygun
- Önerilen karar: `B — KEEP BUT MERGE/CONSOLIDATE LATER`
- Gerekçe: değerli terminoloji kilidi sağlıyor, ancak başka liste testleriyle ciddi metin örtüşmesi var.

### 9.7 `QuoteOrderManualSmokeRouteTest`

- Amaç: 6 liste route’unun manuel smoke görünümü için `200` dönmesini kontrol etmek.
- Route:
  - quote active/converted/archived
  - order open/completed/all
- Kullanıcı/rol: admin
- Fixture/veri: quote/order matrix
- Production karşılığı: route’lar gerçekten açılıyor
- Assertion türleri: yalnız `assertOk`
- Tenant isolation etkisi: dolaylı
- Permission etkisi: hayır
- Finans/maliyet görünürlüğü etkisi: hayır
- Teknik alan sızıntısı koruması: hayır
- Türkçe terminoloji koruması: hayır
- Örtüşme:
  - `QuoteOrderListNoTechnicalUiLeakRegressionTest` aynı URL matrisini zaten daha güçlü assertion ile açıyor
  - `AdminSmokeTest` base routes’u zaten smoke ediyor
- Duplicate riski: yüksek
- Flaky riski: düşük
- Database/global state riski: düşük
- Test süresi: 5412 ms
- Tests-only checkpoint uygunluğu: uygun değil
- Önerilen karar: `D — DO NOT COMMIT`
- Gerekçe: yanlış route veya eksik içerik olsa bile sırf `200` döndüğü için yeşil kalabilir; mevcut daha güçlü testler tarafından fiilen kapsanmış.

## 10. Production Truth Kararı

- Modified label değişiklikleri production ile uyumlu mu: evet
- Quote active/converted/archived ayrımı current worktree production mantığıyla uyumlu mu: evet
- Order active/completed/payment_pending ayrımı current worktree production mantığıyla uyumlu mu: evet
- Permission bazlı total/finance görünürlüğü üretim mantığıyla uyumlu mu: evet
- Tenant scope üretim mantığıyla uyumlu mu: evet
- Production kodu eksikliği bulundu mu: hayır
- Testlerden biri production truth ile çelişti mi: hayır

## 11. Önerilen Checkpoint

| Grup | Dosyalar | Ön Koşul | Test Matrisi | Risk |
|---|---|---|---|---|
| Quote/Order List UX and Terminology Tests | `PromotionQuoteAndOrderIndexHeaderPanelTest.php`, `PromotionQuoteAndOrderIndexUxTest.php`, `QuoteOrderListTurkishTerminologyTest.php` | mevcut worktree quote/order list production davranışının korunması | hedef 3 dosya + `PromotionQuoteAndOrderIndex|QuoteOrderListTurkishTerminology` | orta-düşük |
| Quote/Order List Security and Tenant Isolation Tests | `QuoteOrderListNoSensitiveLeakTest.php`, `QuoteOrderListNoTechnicalUiLeakRegressionTest.php`, `QuoteOrderListTenantIsolationTest.php` | aynı route’larda tenant/permission/safe render davranışının korunması | hedef 3 dosya + `QuoteOrderListNoSensitiveLeak|QuoteOrderListNoTechnicalUiLeakRegression|QuoteOrderListTenantIsolation` | düşük |

### Net checkpoint kararı

- `SONUÇ 2 — İKİ AYRI TEST COMMITİ GEREKİR`
- Gerekçe:
  - UX/terminoloji testi ile security/isolation testi farklı bakım eksenlerine sahip
  - `QuoteOrderManualSmokeRouteTest` commit dışı kalmalı
  - modified testler kullanıcı-facing davranış kilidi; untracked security testleri ayrı risk kümesi

## 12. Tests-Only Commit Sınırı Değerlendirmesi

- Bu fazda tests-only checkpoint teknik olarak mümkün: evet
- Ancak tek commit yerine iki grup daha güvenli: evet
- Production fix gerektiren eksik davranış bulundu: hayır
- Manual smoke test aynı pakete eklenmeli mi: hayır

## 13. Audit Sonu Git Kontrolü

- `git status --short`
  - hedef modified testler değişmeden kaldı
  - hedef untracked testler değişmeden kaldı
  - staged alan boş kaldı
- `git diff --cached --stat`: boş
- `git diff --cached --name-status`: boş
- Audit sırasında oluşan tek yeni dosya:
  - `docs/QUOTE-ORDER-LIST-TESTS-ONLY-HARDENING-PREP-RAPORU-20260710.md`

## 14. Son Karar

- Yedi hedef testin tamamı incelendi: evet
- Yedi hedef testin tamamı çalıştırıldı: evet
- Assertion-production eşleme yapıldı: evet
- Duplicate/örtüşme analizi yapıldı: evet
- Production kodu değiştirilmeden tests-only prep tamamlandı: evet
