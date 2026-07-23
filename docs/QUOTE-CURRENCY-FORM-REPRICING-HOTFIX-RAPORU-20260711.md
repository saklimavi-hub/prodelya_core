# Quote Currency Form Repricing Hotfix Raporu — 2026-07-11

## 1. Kök neden
- Kök neden iki parçalıydı: quote satırı ilk seçimde eski `list_price/display_price` akışına yaslanıyordu ve currency değişiminde satır fiyatları yalnız etiket bazında değişiyor, backend repricing tekrar tetiklenmiyordu.
- Catalog search ve live product info payloadlarında source/document fiyat ayrımı yeterince kullanılmıyordu.
- Manual unit price aktif olduğunda girilen rakamın hangi document currency'ye ait olduğu saklanmadığı için para birimi değişiminde aynı sayının yeni currency etiketiyle kalma riski vardı.
- İlk seçim sonrası quantity `1` olsa bile satır ve sağ özet bazı akışlarda tekrar hesaplanmadığı için `0,00` toplam görülebiliyordu.

## 2. Değişen dosyalar
- `app/Http/Controllers/Admin/CatalogSearchController.php`
- `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`
- `app/Services/PromotionQuote/QuoteCurrencyPricingService.php`
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- `tests/Feature/ProductHubLiveProductInfoEndpointTest.php`
- `tests/Feature/PromotionQuoteCurrencySnapshotTest.php`
- `tests/Feature/PromotionQuoteHasPrintFirstRowQuantityRegressionTest.php`

## 3. Response contract
- Canonical backend currency korunur: `TRY`, `USD`, `EUR`.
- Catalog search ve live product info artık güvenli quote contract alanlarını taşır:
  - `quote_price_value`
  - `quote_currency`
  - `quote_price_status`
  - `quote_price_snapshot`
- Manual repricing için live product info tarafında ayrıca:
  - `manual_quote_price_value`
  - `manual_quote_currency`
  - `manual_quote_price_status`
  - `manual_quote_price_snapshot`
- Source/base ayrımı public payloadda korunur; row binding document currency fiyatını kullanır.

## 4. İlk ürün seçimi düzeltmesi
- Catalog search request artık seçili quote currency ve `quote_date` bilgisini taşır.
- Form satırı normalize edilirken `quote_price_value` ve `quote_currency` eski `list_price/display_price` alanlarından önce değerlendirilir.
- Ürün seçildiğinde satır quantity boşsa güvenli olarak `1` atanır.
- İlk seçim sonrası satır total ve sağ özet yeniden hesaplanır.

## 5. TRY/USD/EUR repricing
- Currency değişiminde manual olmayan satırlar backend live product info üzerinden yeniden fiyatlanır.
- Runtime Currency Core sonucu:
  - `12.50 USD -> 586.16 TRY`
  - `12.50 USD -> 12.50 USD`
  - `12.50 USD -> 10.93 EUR`
- 10 adet toplamlar:
  - `TRY`: `5,861.60`
  - `USD`: `125.00`
  - `EUR`: `109.30`

## 6. Manual fiyat currency davranışı
- Manual override aktifken `manual_entry_currency` ve `manual_entry_amount` snapshot içinde saklanır.
- Currency değişiminde manual değer aynı numerik rakamla taşınmaz; backend Currency Core üzerinden yeni document currency'ye çevrilir.
- Runtime conversion sonucu:
  - `600 TRY -> 12.80 USD`
- `manual_sales_price_override` davranışı korunur.

## 7. Quantity/total düzeltmesi
- Ürün seçildiğinde satır quantity boşsa `1` ile normalize edilir.
- `line_total`, `price_snapshot` toplam alanları ve sağ özet her repricing turunda yeniden hesaplanır.
- Quantity, iskonto ve unit price input akışları korunmuştur.

## 8. Kur bulunamadı davranışı
- Güvenli status kümesi dışında (`converted`, `not_required`, `stale_rate`) row fiyatı uygulanmaz.
- Böyle durumda satır fiyat alanı boşaltılır, `0.00` total yazılır ve kullanıcıya `Guncel kur bulunamadi. Kur bilgilerini kontrol edin.` mesajı gösterilir.
- 1:1 sessiz fallback eklenmedi.

## 9. Güvenlik
- Browser tarafı authoritative rate kaynağı yapılmadı.
- Repricing backend `QuoteCurrencyPricingService` üzerinden yapılıyor.
- Public payloadlara `conversion_legs`, margin benzeri iç detaylar eklenmedi.
- Tenant scope ve supplier access kontrolleri mevcut endpointlerde korunuyor.

## 10. Hedefli test sonuçları
- `php artisan test --filter=CatalogSearchCurrencyPayloadTest` ✅
- `php artisan test --filter=ProductHubLiveProductInfoEndpointTest` ✅
- `php artisan test --filter=PromotionQuoteCurrencySnapshotTest` ✅
- `php artisan test --filter=PromotionQuoteCreateEditUiRegressionTest` ✅
- `php artisan test --filter=PromotionQuoteHasPrintFirstRowQuantityRegressionTest` ✅
- `php artisan test --filter="PromotionQuote|Currency"` ✅ `175 tests`
- `php artisan test --filter="AdminSmokeTest|FullOperationalFlowSmokeTest"` ✅ `60 tests`

## 11. Development DB sayaçları
- Test öncesi:
  - `tenants = 6`
  - `tenant_catalog_products = 18032`
  - `orders = 30`
- Test sonrası:
  - `tenants = 6`
  - `tenant_catalog_products = 18032`
  - `orders = 30`

## 12. HTTP/browser smoke
- In-app browser ile manuel smoke denenmeye çalışıldı ancak browser runtime Windows ACL hatasıyla açılamadı: `windows sandbox failed: helper_unknown_error: apply deny-read ACLs`.
- Bu nedenle otomatik test + runtime conversion doğrulaması tamamlandı, gerçek browser retest kullanıcı adımına bırakıldı.
- Tekrar kontrol URL'si:
  - `/admin/promotion-quotes/create`

## 13. Final Git durumu
- `HEAD` değişmedi: `2bd5d749abbdb7c3e61b6b8024d5150287df6aa8`
- Staged alan boş kaldı.
- Commit yapılmadı.
- Worktree zaten kirliydi; kapsam dışı değişiklikler korunmuştur.

## 14. Kalan riskler
- Browser smoke doğrudan araç üzerinden tamamlanamadığı için son UI davranışı gerçek tenant verisiyle kullanıcı retest'ine ihtiyaç duyuyor.
- TRY/EUR/USD repricing contract'ı otomatik test ve runtime service çağrılarıyla doğrulandı; ancak seçilen gerçek ürün kaydı için görsel akış kullanıcı kontrolüyle kapatılmalı.

## 15. Nihai karar
- QUOTE CURRENCY REPRICING FIXED — MANUAL RETEST READY

## 16. Kullanıcının tekrar kontrol edeceği senaryolar
- `/admin/promotion-quotes/create` ekranında `PZ-CH30SY` ürününü seç.
- `TRY` seçiliyken satır fiyatının `586,16 TL` civarında geldiğini ve quantity `1` toplamının sıfır kalmadığını doğrula.
- `USD` seçip satırın `12,50 USD`, quantity `10` toplamının `125,00 USD` olduğunu doğrula.
- `EUR` seçip satırın `10,93 EUR` civarına döndüğünü doğrula.
- Tekrar `TRY` seçip satırın yeniden `586,16 TL` civarına geldiğini doğrula.
- `TRY` iken manual unit price `600` gir, sonra `USD` seç ve değerin `600 USD` olarak kalmadığını, yaklaşık `12,80 USD` olarak çevrildiğini doğrula.
