# Promotion Quote Form JavaScript Bootstrap Runtime Final Hotfix Raporu — 2026-07-11

## 1. Kapsam ve Kural Uyumu
- Faz türü: Runtime hotfix verification + minimal JS bootstrap fix
- Yeni feature eklenmedi.
- Commit veya staging yapılmadı.
- Yalnız `resources/views/admin/promotion-quotes/_form-workspace.blade.php` dosyasında gerçek runtime exception için minimal düzeltme uygulandı.
- Order / Procurement Currency Carryover kapsamına geçilmedi.
- Endpoint 200 ve `node --check` tek başına başarı kabul edilmedi; gerçek form bootstrap zinciri headless browser ile ayrıca doğrulandı.

## 2. Başlangıç Durumu
- Git HEAD: `2bd5d749abbdb7c3e61b6b8024d5150287df6aa8`
- Branch: `feature/master-restructure-phase-2-order-flow`
- Başlangıçta staged alan boştı.
- Çalışma başında ilgili dosya durumu: `M resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- Cache temizliği uygulandı:
  - `php artisan view:clear`
  - `php artisan optimize:clear`
- DB hızlı durum özeti:
  - tenant_count: `6`
  - order_count: `30`
  - saklimavi_tenant_id: `2`

## 3. Gerçek Runtime Kök Neden Tespiti
Headless Chrome ile `http://saklimavi.prodelya_core.test/admin/promotion-quotes/create` sayfası gerçek tenant hostu altında, `admin@saklimavi.local` kullanıcısı ile açıldı.

İlk gerçek runtime exception:
- Tür: `ReferenceError`
- Mesaj: `Cannot access 'currency' before initialization`
- İlk gözlenen çağrı zinciri:
  - `recalculateTotals()`
  - `mountItems()`
  - `DOMContentLoaded` bootstrap

Etkisi:
- Bootstrap zinciri ilk yüklemede kırıldığı için müşteri araması, ürün araması, para birimi seçenekleri ve toplam alanları aynı JavaScript akışı içinde doğru bağlanamıyordu.

## 4. Uygulanan Minimal Düzeltme
Hedef dosya:
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`

Yapılan düzeltme:
- `recalculateTotals()` içinde kullanılan `currency` değişkeni, item döngüsü sonrasında değil, döngüden önce tanımlandı.
- Fallback kodu `TRY` olacak şekilde hizalandı.

Amaç:
- Bootstrap sırasında ilk çalışan toplam hesaplama zincirinin `ReferenceError` ile kırılmasını önlemek.

## 5. Gerçek Form Bootstrap Kanıtı
Aşağıdaki kanıtlar gerçek sayfada headless browser ile alındı.

### 5.1 Render ve Bootstrap
- Create sayfası açıldı: `200 OK`
- URL doğrulandı: `http://saklimavi.prodelya_core.test/admin/promotion-quotes/create`
- Runtime exception sayısı düzeltme sonrası: `0`
- Global fonksiyonlar erişilebilir durumda:
  - `performCustomerSearch`: `function`
  - `performCatalogSearch`: `function`
  - `recalculateTotals`: `function`

### 5.2 SAKLImavi Currency Seçenekleri
Gerçek formdaki currency select seçenekleri:
- `TRY` / `TL`
- `USD` / `USD`
- `EUR` / `EUR`

Sonuç:
- Kullanıcının gördüğü “yalnız TL var” davranışı, bootstrap kırılması sonrası oluşan semptomdu; gerçek render ve düzeltme sonrası seçenekler üçlü olarak doğrulandı.

### 5.3 Müşteri Araması
Gerçek form içinde `performCustomerSearch('bar')` çağrısı ile doğrulandı:
- Dropdown görünür oldu: `true`
- Sonuç sayısı: `2`
- Görünen örnek kayıtlar:
  - `BARIŞ MATBAASI`
  - `MK MODA ÜRÜNLERİ SANAYİ VE DIŞ TİCARET LİMİTED ŞİRKETİ`

Sonuç:
- Müşteri arama bootstrap zinciri çalışıyor.

### 5.4 Ürün Araması ve Seçim
Gerçek form içinde ilk ürün satırında `performCatalogSearch(..., '0506')` çağrısı ile doğrulandı:
- Sonuç sayısı: `20`
- İlk seçilebilir sonuç örneği:
  - Ürün adı: `ET-0506-S Plastik Kalem Siyah`
  - Ürün kodu: `ET-0506-S`
  - Liste fiyatı: `9.20`
  - Birim fiyat: `9.20`
  - Satır toplamı: `9.20`
- Sonuç listesi seçim sonrası kapandı: `true`

Not:
- Bu gerçek form probe’unda `0506` aramasında `PZ-CH30SY` gözlenmedi; doğrulanan gerçek selectable kayıt `ET-0506-S` oldu.
- Buna rağmen ürün arama dropdown’ı açıldı, sonuçlar geldi, seçim DOM’a işlendi ve toplamlar güncellendi.

### 5.5 Toplamlar ve Currency Geçişi
Aynı gerçek form oturumunda toplamlar doğrulandı:
- TRY sonrası:
  - `Ürün Toplamı`: `9,20 TL`
  - `Genel toplam`: `9,20 TL`
- USD sonrası:
  - `Ürün Toplamı`: `0,20 USD`
  - `Genel toplam`: `0,20 USD`
- EUR sonrası:
  - `Ürün Toplamı`: `0,17 EUR`
  - `Genel toplam`: `0,17 EUR`

Sonuç:
- Currency select, repricing ve summary total zinciri aynı bootstrap içinde çalışıyor.

## 6. Hedefli Test Sonuçları
Çalıştırılan komut:
- `php artisan test tests\Feature\PromotionQuoteCreateEditUiRegressionTest.php tests\Feature\CatalogSearchCurrencyPayloadTest.php tests\Feature\ProductHubLiveProductInfoEndpointTest.php tests\Feature\PromotionQuoteHasPrintFirstRowQuantityRegressionTest.php tests\Feature\PromotionQuoteCurrencySnapshotTest.php tests\Feature\TenantAccessServiceTest.php tests\Feature\DemoTenantFullAccessTest.php tests\Feature\AdminSmokeTest.php tests\Feature\FullOperationalFlowSmokeTest.php`

Sonuç:
- `89` test geçti
- `893` assertion geçti
- Süre: `14448 ms`
- Yeni failure yok

## 7. Final Git Durumu
- Staged alan: boş
- İlgili modified dosya:
  - `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- Bu rapor dosyası oluşturuldu:
  - `docs/PROMOTION-QUOTE-FORM-JAVASCRIPT-BOOTSTRAP-RUNTIME-FINAL-HOTFIX-RAPORU-20260711.md`

## 8. Net Karar
**PROMOTION QUOTE FORM JS RESTORED — MANUAL RETEST READY**

Manuel retest URL:
- `http://saklimavi.prodelya_core.test/admin/promotion-quotes/create`

Önerilen manuel kontrol başlıkları:
- müşteri araması dropdown açılıyor mu?
- ürün araması dropdown açılıyor mu?
- SAKLImavi currency select içinde TL / USD / EUR birlikte görünüyor mu?
- ürün seçimi sonrası toplamlar currency değişiminde anlık güncelleniyor mu?
