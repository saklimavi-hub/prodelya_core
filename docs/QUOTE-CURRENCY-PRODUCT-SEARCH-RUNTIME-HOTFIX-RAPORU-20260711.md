# Quote Currency Product Search Runtime Hotfix Raporu — 2026-07-11

## 1. Yönetici özeti
- Faz amacı `/admin/promotion-quotes/create` ekranındaki ürün arama kırılmasını dar hotfix ile gidermekti.
- Browser aracı Windows ACL nedeniyle kullanılamadığı için doğrulama, gerçek tenant/auth context ile local Laravel HTTP kernel üzerinden yapıldı.
- Ürün arama endpoint’i yeniden doğrulandı, frontend request contract’ı korunarak runtime kırılması giderildi.
- `0506` araması TRY/USD/EUR/TL için 200 ve sonuç üretir hale geldi.
- `PZ-CH30SY` araması yeniden sonuç veriyor ve document currency repricing payload’ı doğru dönüyor.
- Commit yapılmadı, staging yapılmadı, staged alan boş kaldı.
- Bu turda oluşturulan geçici `.tmp` doğrulama scriptleri iş bitiminde kaldırıldı.

## 2. Kök neden
- Son repricing hotfixi sonrası katalog arama ve live product info akışları, ham `price_snapshot` ile çalışmaya devam ediyordu.
- USD tabanlı ama `currency_snapshot` içermeyen ürünlerde `source_price`, `source_currency`, `base_price` ve buna bağlı `quote_price_value` null kalıyordu.
- Aynı zamanda mevcut fixture/test snapshot’ları da gereksiz yeniden hesaplandığında bozuluyordu.
- Sonuç olarak ürün dropdown’u veri contract’ını güvenli biçimde tamamlayamıyor, runtime’da arama ve seçim akışı fiilen kırılıyordu.

## 3. Runtime console/network kanıtı
- Doğrulanan request yolu: `/admin/catalog/search`
- Authenticated local request örneği: `http://{panel_subdomain}.prodelya_core.test/admin/catalog/search?q=0506&currency=TRY&quote_date=2026-07-11&only_visible=1&only_quote_visible=1`
- Status: `200`
- Response tipi: JSON array
- Browser console doğrudan açılamadı; bunun yerine aynı auth context ile gerçek Laravel kernel request/response kanıtı alındı.
- Hata sonrası durum yerine son doğrulanan runtime sonucu:
  - `0506` TRY: `status=200`, `count=20`, ilk kod `ET-0506-S`
  - `PZ-CH30SY` USD: `status=200`, `count=1`

## 4. Endpoint request/response contract
- UI tarafında belge para birimi canonical olarak backend’e taşınıyor:
  - `TL` etiketi -> `TRY`
  - `USD` -> `USD`
  - `EUR` -> `EUR`
- `quote_date` parametresi `YYYY-MM-DD` formatında kullanıldı: `2026-07-11`
- Endpoint güvenli response alanlarıyla çalıştı:
  - `quote_price_value`
  - `quote_currency`
  - `quote_price_status`
  - `source_price`
  - `source_currency`
  - `base_price`
  - `base_currency`
- Sensitive alan sızıntısı doğrulaması:
  - `purchase_price=absent`
  - `group_code=absent`
  - `raw_price=absent`

## 5. Uygulanan hotfix
- `CatalogSearchController` içinde ürün ve varyant serializer’ları, ham snapshot yerine hazırlanan catalog snapshot path’ine geçirildi.
- `prepareCatalogPriceSnapshot()` helper’ı iki kuralı birlikte sağlayacak şekilde daraltıldı:
  - mevcut güvenilir `currency_snapshot` varsa koru
  - snapshot eksikse runtime projection üret
- `ProductHubLiveProductInfoService` aynı hazırlık yoluna taşındı; seçili ürün canlı bilgi kartı ile arama payload’ı aynı repricing sözleşmesini kullanır hale getirildi.
- `_form-workspace.blade.php` içinde kullanıcı-facing hata/uyarı metni ve arama davranışı korundu:
  - min arama uzunluğu `3`
  - endpoint hata mesajı: `Ürün araması şu anda tamamlanamadı.`
  - kur uyarısı: `Güncel kur bulunamadı. Kur bilgilerini kontrol edin.`

## 6. TRY/USD/EUR arama davranışı
- `0506` için doğrulanan sonuçlar:
  - TRY: `count=20`, ilk ürün quote `9.2 TRY`
  - USD: `count=20`, ilk ürün quote `0.2 USD`
  - EUR: `count=20`, ilk ürün quote `0.17 EUR`
  - TL etiketi: `count=20`, quote currency canonical olarak `TRY`
- Bu doğrulama aynı tenant/auth context ile gerçek HTTP kernel request’i üzerinden yapıldı.

## 7. Ürün seçim ve repricing davranışı
- `PZ-CH30SY` araması artık sonuç veriyor ve repricing payload’ı dolu geliyor.
- Doğrulanan sonuçlar:
  - TRY document: `quote=586.16`, `source=12.5 USD`, `base=586.16 TRY`
  - USD document: `quote=12.5`, `source=12.5 USD`, `base=586.16 TRY`
  - EUR document: `quote=10.93`, `source=12.5 USD`, `base=586.16 TRY`
- Live product info endpoint doğrulaması:
  - `status=200`
  - `quote=10.93`
  - `qcur=EUR`
  - `src=12.5`
  - `scur=USD`

## 8. Hata/boş sonuç davranışı
- Arama callback’ini tamamen kıran pricing boşluğu giderildi.
- Endpoint hata mesajı sade kullanıcı metni olarak korundu: `Ürün araması şu anda tamamlanamadı.`
- Boş sonuç davranışı için mevcut UI contract’ı korunmuştur; bu turda ana runtime kırığı endpoint/payload tarafında çözüldü.
- İnteraktif browser ACL kısıtı nedeniyle dropdown render’ının görsel son kontrolü kullanıcı manuel retestine bırakıldı.

## 9. Tenant ve hassas veri güvenliği
- Tenant scope korunarak sonuçlar yalnız tenant katalogu üzerinden döndü.
- `0506` ve `PZ-CH30SY` doğrulamalarında cross-tenant veri sızıntısı gözlenmedi.
- Sensitive supplier/cost alanları response’a açılmadı.
- Mevcut kirli worktree temizlenmedi; kapsam dışı değişikliklere dokunulmadı.

## 10. Türkçe metin düzeltmesi
- Kullanıcı-facing kur uyarısı düzeltildi:
  - Eski: `Guncel kur bulunamadi. Kur bilgilerini kontrol edin.`
  - Yeni: `Güncel kur bulunamadı. Kur bilgilerini kontrol edin.`

## 11. Değişen dosyalar
- `app/Http/Controllers/Admin/CatalogSearchController.php`
- `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`

## 12. Hedefli testler
- `php artisan test --filter=CatalogSearchCurrencyPayloadTest` -> passed (`3 tests`)
- `php artisan test --filter=ProductHubLiveProductInfoEndpointTest` -> passed (`12 tests`)
- `php artisan test --filter=PromotionQuoteCreateEditUiRegressionTest` -> passed (`5 tests`)
- `php artisan test --filter=PromotionQuoteHasPrintFirstRowQuantityRegressionTest` -> passed (`1 test`)
- `php artisan test --filter=PromotionQuoteCurrencySnapshotTest` -> passed (`5 tests`)
- `php artisan test --filter="PromotionQuote|Currency"` -> passed (`175 tests`)
- `php artisan test --filter="AdminSmokeTest|FullOperationalFlowSmokeTest"` -> passed (`60 tests`)

## 13. Development DB sayaçları
- `tenants=6`
- `tenant_catalog_products=18032`
- `orders=30`
- `saklimavi_visible_quote_products=238`
- Test öncesi ve sonrası DB mutasyon ihtiyacı oluşmadı.

## 14. Runtime smoke
- Browser ACL nedeniyle interaktif smoke yerine authenticated HTTP smoke kullanıldı.
- Doğrulananlar:
  - `0506` yazınca request oluşuyor ve `200` dönüyor.
  - En az bir erişilebilir ürün geliyor.
  - `PZ-CH30SY` sonuç veriyor.
  - TRY/USD/EUR document currency payload’ları doğru dönüyor.
  - Live product info repricing payload’ı da aynı doğruluğu sağlıyor.
- Görsel dropdown açılışı, layout itme davranışı ve console temizliği için son adım manuel browser retest olarak kalıyor.

## 15. Final Git durumu
- HEAD: `2bd5d749abbdb7c3e61b6b8024d5150287df6aa8`
- Branch: `feature/master-restructure-phase-2-order-flow`
- Staged alan: boş
- `git diff --cached --stat`: boş
- Kapsam dışı mevcut modified/untracked dosyalar worktree’de korunmuştur.
- Bu hotfix turunda oluşturulan geçici scriptler kaldırılmıştır.

## 16. Kalan riskler
- İnteraktif browser/console doğrulaması Windows ACL engeli yüzünden bu oturumda yapılamadı.
- Quantity değiştirildiğinde toplam render’ının ekranda görsel olarak doğru aktığı son kez manuel kontrol edilmelidir.
- Dropdown’un layout’u aşağı itmemesi ve hata mesajı/boş sonuç state’inin görsel davranışı manuel browser retest ile teyit edilmelidir.

## 17. Nihai karar
`PRODUCT SEARCH RESTORED — MANUAL RETEST READY`

## 18. Kullanıcının manuel kontrol adımları
1. Multi-currency erişimi açık bir tenant ile `/admin/promotion-quotes/create` ekranını aç.
2. Para birimini sırasıyla `TL`, `USD`, `EUR` seç.
3. Ürün alanına `0506` yaz ve dropdown’un açıldığını doğrula.
4. Ürün alanına `PZ-CH30SY` yaz ve sonucu seç.
5. Satır fiyatının belge para birimine göre dolduğunu doğrula.
6. Quantity değerini `10` yap ve toplamın sıfır kalmadığını doğrula.
7. Browser console’da JS error olmadığını ve network request’in `200` döndüğünü kontrol et.
