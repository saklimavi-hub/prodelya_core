# LIVE-A1 Quote Freshness And Snapshot Guard Report — 2026-07-15

## 1. Executive result
IMPLEMENTED — QUOTE FRESHNESS ATTRIBUTION AND STALE PRICE GUARD READY — MANUAL SNAPSHOT SMOKE PENDING

## 2. Existing freshness service audit
- Shared truth `ProductHubFreshnessDiagnosticService` dar biçimde genişletildi.
- Yeni paralel algoritma yazılmadı; exact variant/product zinciri aynı servis içinde üretildi.
- `projection_lag` semantiği ayrılaştırıldı: timestamp geriliği + eşit değerler blocker değil.

## 3. Canonical freshness DTO
- `CatalogSearch` ve quote save path için ortak DTO alanları bağlandı.
- DTO ana alanları: `status`, `projection_outdated`, `stale_price`, `stale_stock`, `blocking`, `warning_codes`, `message`, `source_updated_at`, `standard_updated_at`, `projection_synced_at`, `checked_at`.

## 4. Exact product/variant behavior
- Flat ürünlerde product zinciri kullanılıyor.
- Tenant variant seçiliyse exact variant zinciri kullanılıyor.
- Parent/sibling fallback için hedefli test eklendi.

## 5. CatalogSearch integration
- Top-level `freshness` eklendi.
- `price_snapshot.freshness_summary` ve `quote_price_snapshot.freshness` bağlandı.
- Currency redaction sözleşmesi korunarak mevcut `CatalogSearchCurrencyPayloadTest` PASS kaldı.

## 6. Live-info integration
- Top-level `freshness` live-info payload’ına eklendi.
- Existing `ProductHubLiveProductInfoEndpointTest` PASS.
- Yeni `CatalogSearchFreshnessPayloadTest` ile CatalogSearch/live-info parity doğrulandı.

## 7. Quote UI warning
- Quote workspace canlı ürün paneli freshness badge/message yüzeyine bağlandı.
- Büyük redesign yapılmadı; mevcut kompakt panel yapısı korundu.
- `PromotionQuoteLiveProductInfoUiTest` PASS.

## 8. Server save guard
- `PromotionQuoteController::resolveCatalogItemPayload()` içinde save-time freshness re-check var.
- `stale_price=true` ise row-level validation path: `items.{index}.tenant_catalog_product_id`.
- `stale_stock` blocker değil; snapshot warning olarak taşınıyor.

## 9. TRY tests
- TRY flat ve TRY exact hedefli fixture testleri PASS.
- Save snapshot tarafında TRY historical immutability PASS.

## 10. USD tests
- USD exact freshness parity PASS.
- USD exact save snapshot attribution PASS.

## 11. Projection lag behavior
- `projection_outdated=true`, `stale_price=false`, `stale_stock=false`, `blocking=false` hedefli test PASS.

## 12. Stale price behavior
- `stale_price=true` server-side save block PASS.
- Silent repricing yok; validation error dönüyor.

## 13. Stale stock behavior
- `stale_stock=true` save allowed PASS.
- Warning summary snapshot içinde taşınıyor.

## 14. Historical immutability
- Save sonrası katalog fiyatı değiştirilince mevcut `order_items.price_snapshot` değişmiyor.
- `PromotionQuoteHistoricalSnapshotImmutabilityTest` PASS.

## 15. Targeted tests
- PASS `ProductHubQuoteFreshnessDiagnosticTest` — 4 test / 18 assertion
- PASS `CatalogSearchFreshnessPayloadTest` — 1 test / 9 assertion
- PASS `ProductHubLiveInfoFreshnessPayloadTest` — 1 test / 7 assertion
- PASS `PromotionQuoteStalePriceSaveGuardTest` — 1 test / 5 assertion
- PASS `PromotionQuoteFreshnessSnapshotTest` — 1 test / 5 assertion
- PASS `PromotionQuoteExactVariantSnapshotAttributionTest` — 1 test / 6 assertion
- PASS `PromotionQuoteHistoricalSnapshotImmutabilityTest` — 1 test / 3 assertion

## 16. Broad tests
- PASS `CatalogSearchCurrencyPayloadTest` — 3 test / 21 assertion
- PASS `ProductHubLiveProductInfoEndpointTest` — 13 test / 57 assertion
- PASS `PromotionQuoteSourceToTryRatePresentationTest` — 1 test / 5 assertion
- PASS `PromotionQuoteCurrencySnapshotTest` — 5 test / 18 assertion
- PASS `ProductDataHubCatalogQuoteFreshnessTest` — 1 test / 23 assertion
- PASS `PromotionQuoteLiveProductInfoUiTest` — 2 test / 78 assertion
- Full broad ProductDataHub / PromotionQuote / TenantCatalog / Order / AdminSmoke çalıştırılmadı.

## 17. Manual snapshot smoke checklist/result
Result: FAILED

### TRY checklist
- Tenant: `SAKLImavi`
- Ürün: `EL-KOD-35`
- Yeni teklif aç.
- Exact kodla ürünü ara.
- Görünen fiyat/para birimi/stok/freshness status not et.
- Taslak kaydet.
- Oluşan `order_id` ve `order_item_id` not et.
- Read-only tinker ile `price_snapshot` karşılaştır.

### USD checklist
- Ürün: `PZ-CH60SY`
- Kontrol et: source `3.50 USD`, exact variant, rate, TRY/base, stock, freshness status.
- Taslak kaydet.
- `order_id` ve `order_item_id` not et.
- Read-only tinker ile `price_snapshot` karşılaştır.

### Read-only inspection
```php
OrderItem::query()
    ->whereIn('id', [$tryItemId, $usdItemId])
    ->get([
        'id',
        'order_id',
        'tenant_catalog_product_id',
        'tenant_catalog_product_variant_id',
        'standard_product_id',
        'standard_product_variant_id',
        'list_price',
        'discount_rate',
        'unit_price',
        'line_total',
        'product_snapshot',
        'price_snapshot',
    ]);
```

## 18. Data mutation status
- Production DB üzerinde otomatik save/sync/apply/project çalıştırılmadı.
- Yalnız test veritabanı fixture mutation’ları kullanıldı.

## 19. Worktree/staging/commit
- Staging yapılmadı.
- Commit yapılmadı.
- İlgisiz route/menu/global CSS alanlarına dokunulmadı.

## 20. Live quote gate
Current state: MANUAL SNAPSHOT SMOKE FAILED
- Manuel `EL-KOD-35` ve `PZ-CH60SY` save + read-only snapshot karşılaştırması PASS olmadığı için gate CLOSED.

## 21. Manual TRY/USD snapshot smoke
- Browser automation exact native `requestSubmit()` ve browser-side POST denemeleriyle iki ayrı taslak save denenmiştir.
- Read-only DB inspection öncesi stop-line tetiklendi çünkü hiçbir gerçek draft quote kaydı oluşmadı; dolayısıyla `order_id` ve `order_item_id` üretilemedi.
- Save failure root cause kanıtı: `#product-items-container` içindeki render edilen `items[...]` alanları `quote-form` ile bağlı değil.
- Browser DOM kanıtı:
  - `productContainerClosestForm = null`
  - örnek `items[0][product_name]` / `items[0][quantity]` / `items[0][list_price]` inputları için `formId = null`, `closestFormId = null`
  - `totalItemInputs = 27`, fakat form binding yok
- Browser POST kanıtı:
  - `requestSubmit()` sonrası form create sayfasına geri dönüyor
  - `fetch(FormData(form))` denemesinde `items[...]` payload oluşmuyor ve server exact `The items field is required.` dönüyor
- TRY result:
  - SKU: `EL-KOD-35`
  - save outcome: FAIL
  - redirect target: `http://saklimavi.prodelya_core.test/admin/promotion-quotes/create`
  - `quote_id`: yok
  - `order_item_id`: yok
  - exact UI ⇄ saved snapshot karşılaştırması: NOT RUN
- USD result:
  - SKU: `PZ-CH60SY`
  - browser values:
    - `tenant_catalog_product_id = 10060`
    - `tenant_catalog_product_variant_id = 32302`
    - `standard_product_id = 5159`
    - `standard_product_variant_id = 16162`
    - `source_price = 3.50`
    - `source_currency = USD`
    - `applied_rate = 46.8914`
    - `base_price_try = 164.12`
    - `visible_stock_quantity = 6500`
  - freshness parity before save:
    - CatalogSearch: `status=stale_price`, `projection_outdated=false`, `stale_price=true`, `stale_stock=false`, `blocking=true`, `warning_codes=[stale_price]`
    - Live-info: `status=stale_price`, `projection_outdated=false`, `stale_price=true`, `stale_stock=false`, `blocking=true`, `warning_codes=[stale_price]`
    - Browser selected item: `status=stale_price`, `projection_outdated=false`, `stale_price=true`, `stale_stock=false`, `blocking=true`, `warning_codes=[stale_price]`
  - save outcome: FAIL
  - server validation/body evidence: `The items field is required.`
  - `quote_id`: yok
  - `order_item_id`: yok
  - exact UI ⇄ saved snapshot karşılaştırması: NOT RUN
- Freshness contract sonucu:
  - USD tarafında save öncesi CatalogSearch / live-info / browser semantiği aynı ve blocker `stale_price=true`
  - Kaydetme sırasında gerçek snapshot materialization test edilemedi çünkü form payload `items` alanı oluşmadı
- Read-only DB inspection:
  - NOT RUN
  - gerekçe: gerçek save sonucu oluşmuş TRY/USD `order_item_id` yok
- No data mutation beyond user-created draft quotes:
  - PASS
  - browser save attempts dışında production code/test/DB update/sync/staging/commit yapılmadı
- Live gate result:
  - MANUAL SNAPSHOT SMOKE FAILED — LIVE QUOTE GATE CLOSED



## LIVE-A3 update — 2026-07-16
- Quote catalog item attribution fix landed in worktree.
- `collectItems()` now preserves `product_snapshot`; `normalizeItem()` selected identity fallback restored.
- Targeted suites for form ownership, item submission, validation rerender, exact variant attribution and stale-price guard passed.
- Broad `PromotionQuote`, `ProductDataHub`, `TenantCatalog`, `CatalogSearch` passed.
- `Order` broad still has separate finance-card drift.
- `AdminSmokeTest::test_promotion_quote_can_store_catalog_item_snapshots` still needs separate fixture attribution.
- Manual browser save + read-only snapshot verification is still pending, so live quote gate is not open yet.

## 22. 2026-07-16 exact attribution addendum
- Exact manual controlled saves doğrulandı:
  - `46 / TK-2026-0025 / ET-0506-MV / order_item 114`
  - `47 / TK-2026-0026 / EL-KOD-35 / order_item 115`
  - `48 / TK-2026-0027 / PZ-CH60SY / order_item 116`
- ET-0506-MV:
  - exact variant zinciri korundu: tenant variant `27668`, standard variant `13299`
  - source `9.20 TRY`, base `9.20 TRY`, identity rate `1`, `fresh`, `stale_price=false`, `blocking=false`
  - print row yok, manual override yok
- EL-KOD-35:
  - flat catalog ürünü korundu: tenant variant `null`, standard variant `null`
  - source `134.00 TRY`, base `134.00 TRY`, identity rate `1`, `fresh`, `stale_price=false`, `blocking=false`
- PZ-CH60SY:
  - exact kimlikler korundu: tenant product `10060`, tenant variant `32302`, standard product `5159`, standard variant `16162`
  - source `3.50 USD`, base `164.12 TRY`
  - satış presentation katmanında exact source→TRY rate `46.8914`, `sales_rate_source=derived`
  - saved document unit `90.27 TRY`, `fresh`, `stale_price=false`, `blocking=false`
- Yeni negatif vaka `49 / TK-2026-0028`:
  - browser kanıtı `30,50 TL` etiket + `0,00` form durumu idi
  - saved DB sıfır değil: order item `117`, code `AK-1020-KIRMIZI`, list `30.50`, unit `16.775`, line `16.775`, grand total `16.78`
  - pre-save live-info probe `200/ok=true` dönmesine rağmen `quote_price_value=null`, `quote_price_status=not_required`
  - bu kombinasyon `applyLiveProductQuotePricing()` içinde satırı boşaltıp `0.00` UI durumuna düşüren parity bug üretir
  - dolayısıyla stale-price guard PASS olsa da live product pricing hydration/parity gate CLOSED

## LIVE-A5C update — 2026-07-16
- AK-1020 controlled resmoke exact save doğrulandı:
  - `50 / TK-2026-0029 / order_item 118 / AK-1020-KIRMIZI`
- Exact identity:
  - `tenant_catalog_product_id=9626`
  - `tenant_catalog_product_variant_id=31440`
  - `standard_product_id=4611`
  - `standard_product_variant_id=15300`
- Exact snapshot:
  - `source_price=30.50 TRY`
  - `base_price=30.50 TRY`
  - `applied_rate=1`
  - `rate_source=identity`
  - `suggested_sales_unit_price_document=16.78`
  - `actual_sales_unit_price_document=16.78`
  - `manual_sales_price_override=false`
- Exact freshness:
  - `status=fresh`
  - `projection_outdated=false`
  - `stale_price=false`
  - `stale_stock=false`
  - `blocking=false`
- Saved scalar parity:
  - `list_price=30.50`
  - `unit_price=16.775`
  - `line_total=16.775`
- Bu exact controlled save ile LIVE quote gate tekrar açıldı.
- Current state:
  - VERIFIED — LIVE-A4 EXACT SNAPSHOT ATTRIBUTION — LIVE QUOTE GATE OPEN
