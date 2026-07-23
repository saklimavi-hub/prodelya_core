# LIVE-B1-M10-A-R2 Supplier Local Stock Exact Variant Report
Tarih: 2026-07-17
Durum: RECOVERED — SUPPLIER LOCAL STOCK EXACT VARIANT ROWS AND VARIANT DETAIL ROUTING — MANUAL SMOKE REQUIRED

## Kapsam
Bu faz yalnız tedarikçiden stoğa alınanlar listesindeki exact varyant satırı, legacy ambiguous product-row gizleme, exact variant detail routing ve ilgili test/report kapılarını kapsadı.

Dokunulmadı:
- Promotion Quote workspace
- quote pricing
- supplier pricing
- reservation/procurement formülü
- StockMovement lifecycle
- CSV import behavior
- staging / commit

## Read-only ET-0506 exact audit
Tenant: 2
Parent product:
- tenant_catalog_product_id: 7817
- ürün: ET-0506 Plastik Kalem
- parent projection local_stock_quantity: 2000.0000

Exact tenant catalog variants:
- 27668 / ET-0506-MV / Mavi / projection local_stock_quantity=1000.0000
- 27676 / ET-0506-K / Kırmızı / projection local_stock_quantity=1000.0000
- diğer varyantlar 0.0000

Operational tenant_local_stocks truth:
| Stock row | Product | Variant | Scope | On hand | Reserved | Available | Source evidence |
|---|---|---|---|---:|---:|---:|---|
| 1 | ET-0506 | null | product | 2000.0000 | 0.0000 | 2000.0000 | tenant_local_stocks |

Sonuç:
- Varyantlı ürün için current operational row exact variant değil.
- Parent aggregate row sellable stock truth olarak gösterilemez.
- Canonical UI davranışı: normal listeden hariç + sade legacy warning.

## Historical evidence for controlled correction
Read-only tenant_supplier_purchase_entries son kayıtları:
- id 1 / ET-0506-MV / qty 1000 / LOCAL-MAIN / 2026-07-03 08:27:08Z
- id 2 / ET-0506-K / qty 1000 / LOCAL-MAIN / 2026-07-03 08:27:36Z

Karar:
- Exact historical evidence mevcut.
- Bu fazda otomatik split uygulanmadı.
- Sonraki controlled correction akışı için kanıt yeterli, ama business confirmation + explicit correction fazı gerekir.

## Query root cause
Önceki supplier-local liste product aggregation tabanlıydı:
- product bazlı group/sum ile exact variant identity kayboluyordu
- parent product sellable row gibi render oluyordu

Ek runtime bulgusu:
- current stock_movements şemasında `tenant_local_stock_id` kolonu yok
- bu nedenle last movement lookup exact local-stock foreign key üzerinden her ortamda güvenli değildi
- recovery: kolon yoksa last movement null-safe bırakıldı; stock/procurement/pricing davranışı değiştirilmedi

## Uygulanan recovery
- `TenantLocalProductQueryService` supplier-local listeyi product aggregation yerine `tenant_local_stocks` exact rows üzerinden üretir hale getirildi.
- Exact variant row DTO eklendi:
  - `identity_type`
  - `tenant_catalog_product_id`
  - `tenant_catalog_product_variant_id`
  - `display_name`
  - `sku`
  - `variant_label`
  - `supplier_label`
  - `quantity_on_hand`
  - `quantity_reserved`
  - `quantity_available`
  - `last_stock_movement_at`
  - `detail_url`
- Varyantlı ürün + product-scope stock row artık normal listede render edilmiyor.
- Hero/sidebar toplamları exact sellable rows üzerinden hesaplanıyor; double count engellendi.
- `GET /admin/catalog/{product}/variants/{variant}` exact variant detail route eklendi.
- Variant row `İncele` artık parent değil exact variant detail açıyor.
- Parent detail teknik katalog bağlamı olarak korunuyor.

## Testler
Hedefli PASS:
- `SupplierLocalStockExactVariantRowsTest`
- `SupplierLocalStockTwoVariantsSameParentTest`
- `SupplierLocalStockDoesNotRenderParentAggregateTest`
- `SupplierLocalStockLegacyUnassignedExcludedTest`
- `SupplierLocalStockFlatProductRowTest`
- `SupplierLocalStockExactQuantityTotalsTest`
- `SupplierLocalStockNoDoubleCountTest`
- `SupplierLocalStockVariantDetailRouteTest`
- `SupplierLocalStockVariantDetailTenantIsolationTest`
- `SupplierLocalStockVariantDetailProductBindingTest`
- `SupplierLocalStockTemplateContractTest`
- `LocalProductsSupplierStockListTest`
- `TenantCatalogProductSourceResolverTest`
- `OrderLocalStockReservationTest`
- `TenantCatalogProductDetailTemplateTest`
- `LocalProductsAuthorizedRouteSmokeTest`

Broad PASS:
- `TenantCatalog`
- `Stock`
- `LocalProducts`
- `CatalogSearch`
- `PromotionQuote`
- `AdminSmokeTest`
- `php artisan view:cache`

## Worktree / staging / commit
- Staging yapılmadı.
- Commit yapılmadı.
- ET-0506 historical 2000 stock row auto-split edilmedi.

## Manual smoke checklist
Beklenen route:
- `/admin/catalog/local-products/supplier-stock`

Beklenen:
- exact variant operational rows varsa yalnız onlar görünür
- varianted product + legacy product-scope row varsa parent aggregate görünmez
- sade warning görünür: `Varyantı belirlenmemiş stok kaydı bulunuyor.`
- exact variant row `İncele` exact variant detail açar
- no 404 / 405 / 500
