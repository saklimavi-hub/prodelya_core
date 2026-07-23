# LIVE-B1-M9A — Broad Regression and Migration Readiness Report — 2026-07-16

## 1. Sonuç

Durum:
- `NOT READY — ORDER BROAD CLEAN DEĞİL, MİGRATION APPLY ONAY KAPISI HENÜZ AÇIK DEĞİL`

Bu turda hedeflenen iki broad failure kapatıldı:
- `Tests\Feature\ProductSelectionWarningDisplayTest::test_quote_show_displays_local_stock_priority_and_supplier_stock_snapshot` → PASS
- `Tests\Feature\OrderShowTrackingScreenTest::test_orders_show_renders_as_tracking_screen_with_module_links` → PASS

Ancak full `Order` broad yeniden koşulduğunda daha önce ilk failure arkasında gizli kalan ayrı bir residual test açıldı:
- `Tests\Feature\PrintSetupRequirementCoreTest::test_quote_to_order_conversion_creates_setup_requirements_and_status_actions_update_them_with_tenant_scope`

Bu residual failure, bu turda düzeltilen stock parity veya order tracking route parity değişikliklerinden değil; setup requirement / suspension hattındaki ayrı sözleşmeden kaynaklanıyor.

## 2. Preflight ve migrate --pretend

`php artisan migrate:status`:
- `2026_07_16_120000_add_variant_scope_to_tenant_local_stocks_table` → `Pending`
- `2026_07_16_120100_create_tenant_stock_reservations_table` → `Pending`

`php artisan migrate --pretend`:
- PASS
- yalnız beklenen iki migration SQL'ini üretti
- gerçek migrate çalıştırılmadı
- DB write yapılmadı

## 3. Stock failure attribution ve düzeltme

Eski failure:
- `expected visible/local stock = 12`
- `actual visible_stock_quantity = 2531`

Kanıtlanan ilk drop point:
- `CatalogSearchController` product ve variant serializer'ları effective stock hesabında operational local truth yerine projection/supplier ağırlıklı alanları kullanıyordu.
- `ProductHubLiveProductInfoService` de aynı parity açığını taşıyordu.
- `ProductSelectionWarningDisplayTest` fixture'ı exact operational variant stock row üretmiyordu.

Uygulanan dar düzeltmeler:
- `app/Http/Controllers/Admin/CatalogSearchController.php`
  - product path: `effectiveProductLocalStock` ve `effectiveProductStock` operational presenter üzerinden yeniden hesaplandı
  - variant path: `effectiveLocalStock` operational presenter üzerinden yeniden hesaplandı
  - `local_stock_priority` artık projection değil effective local truth'a bakıyor
- `app/Services/ProductDataHub/ProductHubLiveProductInfoService.php`
  - `currentStock` aynı operational local truth kontratına bağlandı
- `tests/Feature/ProductSelectionWarningDisplayTest.php`
  - stale create-page assertion güncellendi
  - exact stock testine `TenantLocalStock` variant fixture eklendi

Sonuç:
- `php artisan test --filter=Stock --stop-on-failure` → PASS

## 4. Order tracking failure attribution ve düzeltme

Eski failure:
- test hard-coded procurement show link beklentisine dayanıyordu
- gerçek UI canonical order detail tab omurgasını (`admin.orders.show?tab=...`) kullanıyordu

Kanıtlanan canonical route/action:
- procurement tab: `route('admin.orders.show', ['order' => $order, 'tab' => 'tedarik'])`
- production tab: `route('admin.orders.show', ['order' => $order, 'tab' => 'uretim'])`
- delivery tab: `route('admin.orders.show', ['order' => $order, 'tab' => 'teslimat'])`

Uygulanan dar düzeltmeler:
- `tests/Feature/OrderShowTrackingScreenTest.php`
  - procurement/production/delivery stale assertions tab tabanlı semantic route assertion'lara çevrildi

Sonuç:
- `php artisan test --filter=OrderShowTrackingScreenTest --stop-on-failure` → PASS

## 5. Broad gate sonuçları

PASS:
- `Stock`
- `Procurement`
- `PromotionQuote`
- `CatalogSearch`
- `TenantCatalog`
- `CurrentAccount`
- `AdminSmokeTest`

FAIL:
- `Order`
  - residual exact failure:
  - `Tests\Feature\PrintSetupRequirementCoreTest::test_quote_to_order_conversion_creates_setup_requirements_and_status_actions_update_them_with_tenant_scope`
  - observed assertion: expected `setupRequirements` count `1`, actual `0`
  - attribution: setup requirement lifecycle / feature-suspension hattında ayrı drift; M9A kapsamındaki iki failure ile aynı kök neden değil

## 6. M7 / M8 / M9 docs cleanup

Bu turda:
- `docs/LIVE-B1-M7-LOCAL-STOCK-TRUTH-AND-PROCUREMENT-SHORTFALL-REPORT-20260716.md`
  - tarihsel/current-state başlıkları normalize edildi
- `docs/LIVE-B1-M9-LOCAL-STOCK-CONTROLLED-CORRECTION-DRY-RUN-REPORT-20260716.md`
  - tarihsel/current-state başlıkları normalize edildi
- `docs/LIVE-B1-M8-EXACT-LOCAL-STOCK-RESERVATION-AND-SHORTFALL-REPORT-20260716.md`
  - mevcut içerik read-only doğrulandı; belirgin bozuk karakter / kırık tarihsel-current-state ayrımı bulunmadı

## 7. Güvenlik ve write sınırı

Bu turda yapılmayanlar:
- `php artisan migrate`
- exact variant stock write
- reservation create
- `TS-2026-0015` correction
- StockMovement write
- CurrentAccount write
- notification write
- staging
- commit

`ET-0506-MV exact operational local stock = 1000` kullanıcı adına onaylanmadı.

## 8. Kapanış kararı

M9A sonucu:
- iki hedef broad failure temizlendi
- migration schema readiness `pretend` seviyesinde doğrulandı
- fakat full `Order` broad temiz olmadığı için migration approval gate henüz açık değildir

Net karar:
- `NOT READY — residual Order broad failure açık`
