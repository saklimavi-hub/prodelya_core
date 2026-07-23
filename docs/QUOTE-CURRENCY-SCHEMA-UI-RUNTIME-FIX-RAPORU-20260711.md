# Quote Currency Schema UI Runtime Fix Raporu — 2026-07-11

## 1. Yönetici özeti
- Emergency restore sonrası eksik kalan `2026_07_10_210000_add_quote_currency_snapshot_fields.php` migration’ı kontrollü şekilde uygulandı.
- Active development DB için yeni pre-alignment backup alındı ve SHA-256 / integrity doğrulandı.
- `multi_currency` erişimi canonical modül/paket sistemi üzerinden açıldı: Demo `demo_tenant_catalog_access`, SAKLImavi `package_module`.
- Catalog search ve live product info payload’ları seçili teklif belge para birimine göre önerilen fiyat üretecek şekilde tamamlandı.
- Quote formda currency değişiminde seçili katalog satırları yeniden fiyatlanıyor; manuel satış fiyatı ezilmiyor.
- Staging yapılmadı, commit oluşturulmadı, staged alan boş kaldı.

## 2. Başlangıç Git ve DB durumu
- HEAD: `2bd5d749abbdb7c3e61b6b8024d5150287df6aa8`
- Branch: `feature/master-restructure-phase-2-order-flow`
- Başlangıç staged alanı: boş
- Runtime DB driver: `sqlite`
- Runtime DB path: `C:\laragon\www\prodelya_core\database\database.sqlite`
- Başlangıç tenant sayısı: `6`
- Başlangıç `tenant_catalog_products`: `18032`
- Başlangıç `orders`: `30`

## 3. Migration öncesi backup
- Backup yolu: `database/backups/database-before-quote-currency-schema-alignment-20260711-142913.sqlite`
- Kaynak DB integrity: `ok`
- Backup integrity: `ok`
- Kaynak / backup SHA-256: `87B6755A064986F7F7C7517D0F087224BA649236DAB4D9A8539066725929DE5D`
- Backup Git’e stage edilmedi.

## 4. Quote Currency schema alignment
- İncelenen migration: `database/migrations/2026_07_10_210000_add_quote_currency_snapshot_fields.php`
- Restore sonrası eksik kolonlar doğrulandı.
- Uygulanan komut: `php artisan migrate --path=database/migrations/2026_07_10_210000_add_quote_currency_snapshot_fields.php --force`
- Son durum:
  - migrations kaydı var: `1`
  - `orders` quote currency kolonları mevcut
  - `order_item_prints.pricing_snapshot` mevcut
  - tenant sayısı: `6`
  - `orders`: `30`
  - `order_items`: `40`
  - `order_item_prints`: `39`
  - integrity: `ok`

## 5. Currency Core ve kullanılan kur kaynağı
- Truth source olarak mevcut Currency Core servisleri kullanıldı; yeni kur motoru veya HTTP entegrasyonu eklenmedi.
- Product-side projection: `App\Services\ProductDataHub\ProductHubCurrencyService`
- Quote document pricing: `App\Services\PromotionQuote\QuoteCurrencyPricingService`
- 2026-07-11 tarihli service smoke sonucunda kullanılan fallback-ready kaynaklar:
  - USD/TRY: `46.8927`, kaynak `tcmb`, rate date `2026-07-10`, type `forex_selling`
  - EUR/TRY: `53.6159`, kaynak `tcmb`, rate date `2026-07-10`, type `forex_selling`
- Restore edilmiş dev DB içinde canlı tenant katalogunda USD/EUR source-currency örneği bulunmadığı için sayısal TRY/USD örnekleri service smoke ile doğrulandı.

## 6. Demo Şirketi multi_currency erişimi
- Öncesi kök neden: modül katalog durumu `planned` olduğu için Demo full access kuralı TL dışına açılmıyordu.
- Sonrası durum:
  - `enabled: true`
  - `reason: demo_tenant_catalog_access`
  - `source: demo_catalog`
  - `module_status: active`
- Hardcode tenant adı bypass’ı eklenmedi.

## 7. SAKLImavi multi_currency erişimi
- Sonrası durum:
  - `enabled: true`
  - `reason: package_module`
  - `source: package`
  - `package_key: enterprise`
  - `module_status: active`
- Canonical dayanak: aktif modül statüsü + enterprise package entitlement.

## 8. Teklif para birimi seçenekleri
- Multi-currency kapalı tenant için backend normalize davranışı korunuyor: yalnız `TRY`.
- Multi-currency açık tenant için quote form seçenekleri `TRY / USD / EUR` payload’ından besleniyor.
- Form label’ları kullanıcıya `TL / USD / EUR` olarak sunuluyor.
- Quote currency durum metni ve quote var ise `Kuru Yenile` / `Mevcut Kuru Koru` aksiyonları korunuyor.

## 9. USD/EUR kaynak ürün dönüşüm davranışı
- Catalog search artık `currency` query param alıyor ve sonuç satırını seçili belge para birimine göre önerilen fiyatla döndürüyor.
- Live product info endpoint artık `quote_price_value`, `quote_currency`, `quote_price_status` ve güvenli warning mesajı döndürüyor.
- 12,50 USD service smoke örneği:
  - TL belge fiyatı: `586,16 TL`
  - USD belge fiyatı: `12,50 USD`
  - kur kaynağı: `tcmb`
  - kur tarihi: `2026-07-10`
- Kur yoksa endpoint `Güncel kur bulunamadı. Kur bilgilerini kontrol edin.` mesajı üretecek şekilde güvenli kaldı; sessiz 1:1 yapılmadı.

## 10. Manual satış fiyatı koruması
- Form tarafında live repricing yalnız önerilen alanları güncelliyor.
- `manual_unit_price` açık satırlarda kullanıcı birim fiyatı korunuyor.
- Hidden `price_snapshot` içinde `manual_sales_price_override` korunuyor.
- `PromotionQuoteCurrencySnapshotTest` yeşil: refresh sonrası manuel satış fiyatı ezilmiyor.

## 11. Commit 2 UI/customer-facing tamamlamaları
- `CatalogSearchController` seçili belge para birimine göre satır fiyatı üretiyor.
- `ProductHubLiveProductInfoService` quote satırı için güvenli document-currency price payload’ı döndürüyor.
- `_form-workspace.blade.php`:
  - aramaya seçili currency query param gönderiyor
  - live product info request’ine currency ekliyor
  - currency değişiminde seçili ürün satırlarını yeniden fiyatlıyor
  - manual override açık satırı koruyor
- Customer-facing/PDF/approval regresyon testleri yeşil kaldı.

## 12. Güvenlik ve tenant izolasyonu
- Bütün tenantlara koşulsuz USD/EUR açılmadı.
- Access kararı tenant access + package/module sistemi üzerinden veriliyor.
- Public/PDF regression testleri geçti; raw rate / cost leak yönünde yeni bir diff üretilmedi.
- Browser’dan gelen raw total authoritative truth yapılmadı; server-side pricing snapshot kullanılmaya devam ediyor.

## 13. Hedefli testler
- Geçen testler:
  - `TenantAccessServiceTest`
  - `DemoTenantFullAccessTest`
  - `CatalogSearchCurrencyPayloadTest`
  - `PromotionQuoteCurrencySnapshotTest`
  - `ProductHubLiveProductInfoEndpointTest`
  - `PromotionQuoteCreateEditUiRegressionTest`
  - `PublicQuoteApprovalCustomerPriceDisplayTest`
  - `PromotionQuotePdfOutputTest`
  - `QuoteSend|QuoteApproval|PublicQuoteApproval`
  - `TenantAccess|PackageOverview|PackageOverride`
  - `AdminSmokeTest|FullOperationalFlowSmokeTest`
- Package/demo testlerinde eski menü label beklentileri güncel tenant menüsü ve ASCII package ekranı ile hizalandı.

## 14. Development DB test izolasyonu
- Testler sonrası runtime sayaçları:
  - tenants: `6`
  - `tenant_catalog_products`: `18032`
  - orders: `30`
- Test DB izolasyonu bozulmadı.

## 15. HTTP/browser smoke
- İnteraktif browser smoke bu turda çalıştırılmadı.
- Yerine service/feature smoke ile şu başlıklar doğrulandı:
  - quote create/edit ekranı render
  - live product info endpoint
  - customer-facing approval
  - PDF output
  - admin/full operational smoke grupları
- Manuel görsel kontrol için ekran hazırlandı; aşağıdaki adımlar Bölüm 18’de verildi.

## 16. Değişen dosyalar
- `config/prodelya_modules.php`
- `database/seeders/PackageSeeder.php`
- `app/Http/Controllers/Admin/CatalogSearchController.php`
- `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`
- `resources/views/admin/promotion-quotes/_form-workspace.blade.php`
- `tests/Feature/DemoTenantFullAccessTest.php`
- `tests/Feature/SuperAdminTenantPackageOverrideTest.php`
- `tests/Feature/TenantPackageOverviewTest.php`
- Runtime DB:
  - `database/database.sqlite`
  - `database/backups/database-before-quote-currency-schema-alignment-20260711-142913.sqlite`
- Not: worktree’de bu faz öncesinden gelen ilgisiz modified/untracked dosyalar korunmuştur.

## 17. Final Git durumu
- Staged alan: boş
- Commit: yapılmadı
- HEAD: hâlâ `2bd5d74`
- Kapsam dışı dirty worktree korunuyor.

## 18. Manuel kontrol adımları
- Multi-currency açık tenant ile `/admin/promotion-quotes/create` aç.
- Para birimi dropdown’ında `TL`, `USD`, `EUR` seçeneklerini doğrula.
- Katalogdan ürün seç:
  - canlı kart kaynak fiyatı ve uyarı alanı gelsin
  - satır `Liste / Birim Fiyat` seçili belge para biriminde dolsun
- Currency’yi `TL -> USD -> EUR` değiştir:
  - manuel olmayan satır yeniden fiyatlansın
  - manuel değiştirilmiş satır korunmuş kalsın
- Mümkünse `/admin/promotion-quotes/{id}/edit` ve `/admin/promotion-quotes/{id}` ekranlarında aynı davranışı kontrol et.

## 19. Kalan riskler
- Restore edilmiş development DB’de canlı tenant katalogunda USD/EUR source-currency örneği bulunmadığı için gerçek tenant katalog örneğiyle görsel smoke kullanıcı tarafından tamamlanmalı.
- Browser smoke yapılmadı; bu nedenle son UX doğrulaması kullanıcı kontrolünde kalıyor.
- Quote/order worktree’de faz öncesinden gelen başka modified dosyalar mevcut; bu faz onları temizlemedi.

## 20. Nihai karar
- QUOTE CURRENCY RUNTIME READY — MANUAL UX CONTROL

## 21. Sonraki kesin adım
- Kullanıcı, multi-currency açık tenant ile `/admin/promotion-quotes/create` ekranında TL/USD/EUR dropdown ve satır repricing davranışını manuel doğrulasın.
- Manuel UX onayı alındıktan sonra bu faz için selective staging / checkpoint hazırlığına geçilebilir.
