# SALES / PROCUREMENT PRICE-CURRENCY TRUTH AUDIT

Date: 2026-07-14
Phase: `PRODELYA_V1_10.16.5_F1P0`
Mode: Read-only audit

## 1. Executive Summary

Satış fiyat/currency zinciri repo içinde büyük ölçüde izlenebilir durumdadır. Product Hub ve quote pricing katmanı `source_price`, `source_currency`, `base_price`, `document_currency`, kur tarihi ve manual sales override bilgisini taşıyabilmektedir.

Tedarik zinciri aynı doğrulukta değildir. Procurement request tarafında alış liste fiyatı için canonical supplier source sözleşmesi yoktur; `SupplierProcurementRequestDataBuilder::suggestPurchaseListPrice()` procurement snapshot, product snapshot ve en sonda quote/order item `price_snapshot.list_price` dahil birçok kaynağı karıştırmaktadır. Bu nedenle satış liste fiyatının procurement alış liste fiyatı yerine yanlışlıkla taşınması yapısal olarak mümkündür.

Ek olarak procurement item tablosunda original currency, FX rate, FX date, base/original snapshot veya manual purchase override nedeni için alan yoktur. Supplier current account sync de borç para birimini supplier truth yerine `order.currency` veya tenant default currency üzerinden üretmektedir. Bu yüzden F1R/F1H2 procurement worktree değişiklikleri bu audit kapanmadan commit edilmemelidir.

Sonuç:

`VERIFIED — SALES / PROCUREMENT PRICE AND CURRENCY TRUTH MAPPED — IMPLEMENTATION GATE READY`

Bu sonuç implementation izni anlamına gelir; mevcut procurement difflerinin güvenli olduğu anlamına gelmez.

## 2. Critical Bug Statement

Kritik risk:

- `SATIŞ LİSTE FİYATI != TEDARİKÇİ LİSTE FİYATI`
- Repo şu anda bu ayrımı procurement kaleminde canonical olarak korumuyor.
- Dövizli supplier liste fiyatının original currency provenance'ı procurement item üstünde saklanmıyor.
- Supplier cari hareketi procurement source currency yerine order/tenant currency ile oluşabiliyor.

Bu yüzden mevcut procurement UI'da doğru fiyat kaydediliyor görünse bile şu sorular güvenli biçimde evetlenemiyor:

- Gösterilen alış liste fiyatı gerçekten supplier source mu?
- Gösterilen TL karşılığı hangi kur snapshot'ından türedi?
- Completed düzeltme aynı historical truth üzerinde mi çalışıyor?
- Cari hareket gerçekten supplier purchase truth'tan mı besleniyor?

## 3. Current Field Map

### Supplier/raw truth

- `app/Models/SupplierProductRaw.php`
- Alanlar: `purchase_price`, `currency`, `source_price`, `source_currency`
- `getFormattedPrice()` önce `purchase_price`, sonra `source_price`; currency olarak `currency`, sonra `source_currency` kullanıyor.

### Standard product truth

- `app/Models/StandardProduct.php`
- Alanlar: `purchase_price`, `purchase_currency`, `selling_price`, `selling_currency`, `currency`, `min_purchase_price`, `max_purchase_price`
- Bu model satış ve alış truth'unu ayrı kavramsal alanlarla taşıyabiliyor.

### Tenant sales/catalog truth

- `app/Models/TenantCatalogProduct.php`
- Alanlar: `display_price`, `sale_price`, `currency`
- `getFormattedSellingPrice()` `display_price -> selling_price -> sale_price` sırasını kullanıyor.

### Historical snapshot model

- `app/Models/ProductPriceSnapshot.php`
- Alanlar: `purchase_price`, `purchase_currency`, `selling_price`, `selling_currency`
- Ayrı purchase/selling truth için uygun bir historical yapı var.

### Quote/order item persisted fields

- `app/Models/OrderItem.php`
- Scalar alanlar: `list_price`, `discount_rate`, `unit_price`, `line_total`
- JSON alanlar: `product_snapshot`, `price_snapshot`, `stock_snapshot`

### Procurement persisted fields

- `database/migrations/2026_06_13_120100_create_supplier_procurement_request_items_table.php`
- Alanlar: `purchase_list_price`, `discount_rate`, `purchase_unit_price`, `purchase_total`
- Currency/original amount/rate/rate_date/manual_reason alanları yok.

### Procurement linkage snapshot

- `database/migrations/2026_06_13_100000_create_order_item_procurements_table.php`
- JSON alanlar: `snapshot`, `procurement_snapshot`
- `app/Models/OrderItemProcurement.php` bunları array cast ediyor.
- Ancak procurement item scalar alanlarında canonical currency contract yok.

## 4. Product Hub Source Map

`app/Services/ProductDataHub/ProductHubLiveProductInfoService.php` satış tarafı için en güçlü source kanıtını veriyor.

`prepareCatalogPriceSnapshot()` ve `resolve()` zincirinde:

- `source_price = price_snapshot.source_price ?? price_snapshot.list_price ?? fallback display price`
- `source_currency = price_snapshot.source_currency ?? price_snapshot.currency ?? fallback currency`
- ayrıca `base_price`, `base_currency`, `conversion_status`, `rate_date` taşınıyor

Response payload açıkça şunları döndürüyor:

- `source_price`
- `source_currency`
- `base_price`
- `base_currency`
- `applied_rate`
- `rate_date`
- `rate_source`
- `rate_type`
- `quote_price_value`
- `quote_price_snapshot`

`tests/Feature/ProductHubLiveProductInfoEndpointTest.php` içinde USD source senaryosu bunu doğruluyor:

- `source_price = 12.5`
- `source_currency = USD`
- `currency = USD`
- `base_price = 437.5`
- `base_currency = TRY`
- `quote_currency = TRY`

Bu katmanda original foreign price ile TRY karşılığı birlikte taşınabiliyor.

## 5. Sales List Source

Satış liste fiyatı fiilen tenant catalog/Product Hub/sellable truth katmanından geliyor.

Kanıtlar:

- `app/Http/Controllers/Admin/CatalogSearchController.php`
  - `prepareCatalogPriceSnapshot()` source/list/currency alanlarını Product Hub snapshot'ından normalize ediyor.
  - `safeQuotePriceSnapshot()` `source_price`, `source_currency`, `document_currency`, `suggested_sales_unit_price_document`, `actual_sales_unit_price_document`, `manual_sales_price_override` döndürüyor.

- `app/Services/PromotionQuote/QuoteCurrencyPricingService.php`
  - `buildItemPricing()` satış için `source_price`, `source_currency`, `base_price`, `document_currency`, `suggested_sales_unit_price_document`, `actual_sales_unit_price_document`, `manual_sales_price_override`, `rate_date`, `rate_source`, `rate_type` üretiyor.

- `app/Http/Controllers/Admin/PromotionQuoteController.php`
  - Quote create/update akışında `price_snapshot = array_merge(catalogPayload.price_snapshot, pricingSnapshot)` ile order item üstüne yazılıyor.
  - `resolveUnitPricePayload()` `list_price - discount_rate => calculated_unit_price` hesabını yapıyor ve kullanıcı farklı birim fiyat girdiyse manual override işareti üretiyor.

UI tarafında `resources/views/admin/promotion-quotes/_form-workspace.blade.php` halen kullanıcı-facing başlıkta yalnız `Liste` kullanıyor. Aynı dosyada:

- `Güncel fiyat: ...`
- `list_price`
- `quote_price_snapshot`
- `manual_unit_price`

birlikte kullanılıyor. Bu yüzden teknik snapshot güçlü olsa da satış UI etiket sözleşmesi hala bağlamsal olarak zayıf.

## 6. Supplier Purchase List Source

Mevcut canonical source net değildir ve ana problem buradadır.

`app/Services/SupplierProcurementRequestDataBuilder.php` içinde `suggestPurchaseListPrice()` şu sırayla aday arıyor:

- `procurement.snapshot.purchase_list_price`
- `procurement.snapshot.supplier_list_price`
- `procurement.snapshot.source_list_price`
- `procurement.snapshot.price_snapshot.list_price`
- `procurement.snapshot.supplier_price_snapshot.list_price`
- `procurement.snapshot.catalog_price_snapshot.list_price`
- `orderItem.product_snapshot.purchase_list_price`
- `orderItem.product_snapshot.supplier_list_price`
- `orderItem.product_snapshot.source_list_price`
- `orderItem.product_snapshot.list_price`
- `orderItem.product_snapshot.price_snapshot.list_price`
- `orderItem.price_snapshot.purchase_list_price`
- `orderItem.price_snapshot.supplier_list_price`
- `orderItem.price_snapshot.list_price`

Bu tasarımın sonucu:

- procurement supplier truth ile sales truth aynı fallback havuzuna düşüyor
- `orderItem.price_snapshot.list_price` kullanımı satış liste fiyatını procurement tarafına sızdırabiliyor
- candidate zincirinde currency eşleniği yok
- amount var, canonical original currency yok

Karar:

`Procurement list source bugün güvenli değildir.`

## 7. Currency Loss Point

Currency kaybı satış tarafında değil, procurement persistence ve procurement sourcing tarafında başlıyor.

Satış tarafında mevcut durum:

- Product Hub original amount/currency biliyor
- Quote pricing service bunu document/base snapshot'a çeviriyor
- Order item `price_snapshot` bunu saklıyor

Currency kaybı/risk noktaları:

1. `SupplierProcurementRequestItem` tablosunda original currency alanı yok.
2. `suggestPurchaseListPrice()` yalnız amount fallback yapıyor; parallel currency lookup yapmıyor.
3. Procurement ekranı scalar `purchase_list_price`, `purchase_unit_price`, `purchase_total` ile çalışıyor.
4. Supplier current account sync currency olarak supplier truth'u değil `order.currency` veya tenant default'u kullanıyor.

Bu nedenle USD/EUR supplier product procurement ekranında yalnız TL/çıplak numeric olarak görünmeye düşebilir.

## 8. Quote Calculation Flow

Akış:

1. Product Hub tenant catalog/sellable truth döndürür.
2. `CatalogSearchController` catalog price snapshot'ı normalize eder.
3. `QuoteCurrencyPricingService::buildItemPricing()`:
   - source amount/currency alır
   - tenant base currency'yi çözer
   - document currency'yi normalize eder
   - suggested ve actual sales unit price üretir
   - kur snapshot'ı ekler
4. `PromotionQuoteController` item'i `list_price`, `discount_rate`, `unit_price` ve `price_snapshot` ile kaydeder.
5. `QuoteSendSnapshotBuilder` customer-facing snapshot oluşturur.

Satış hesabı bugün fiilen şu modele yakındır:

- source/original price + currency mevcut
- TL/base ve document currency dönüşümü mevcut
- manual satış override ayrı tutulabiliyor

## 9. Procurement Calculation Flow

Akış:

1. `OrderItemProcurement` request adayını taşır.
2. `SupplierProcurementRequestDataBuilder::buildRequestEditData()` procurement item için alış liste fiyatı yoksa `suggestPurchaseListPrice()` çağırır.
3. `SupplierProcurementRequestItem::recalculatePurchaseTotals()`:
   - `purchase_list_price`
   - `discount_rate`
   - `purchase_unit_price`
   - `purchase_total`
   hesaplar.
4. Manual unit override verilirse doğrudan `purchase_unit_price` kullanılır.
5. Historical original currency/rate snapshot procurement item üstünde saklanmaz.

Mevcut hesap şu modele yakındır:

- `purchase_unit_price = round(purchase_list_price * (1 - discount/100), 2)`
- `purchase_total = purchase_unit_price * requested_quantity`

Sorun:

- hesaplanan sayı procurement source amount olabilir de olmayabilir
- canonical supplier original currency bilinmez
- completed aşamasında received quantity ile final borç sözleşmesi ayrıca explicit değildir

## 10. Manual Override Flow

### Manual sales override

Kanıt:

- `PromotionQuoteController::resolveUnitPricePayload()`
- `QuoteCurrencyPricingService::buildItemPricing()`
- `tests/Feature/PromotionQuoteCurrencySnapshotTest.php`

Davranış:

- `calculated_unit_price` ile `unit_price` ayrıdır
- kullanıcı farklı `unit_price` girerse `manual_unit_price = true`
- snapshot içine `manual_sales_price_override = true`
- `actual_sales_unit_price_document` ile `suggested_sales_unit_price_document` ayrı saklanır

### Manual purchase override

Kanıt:

- `SupplierProcurementRequestItem::recalculatePurchaseTotals(?manualUnitPrice, ?quantityOverride)`

Davranış:

- manual unit verilirse doğrudan `purchase_unit_price = round(manualUnitPrice, 2)`
- `purchase_total` seçilen quantity ile yeniden hesaplanır

Eksik:

- manual purchase override ayrı flag olarak persisted değil
- override nedeni persisted değil
- override original source truth'tan ayrıştırılmış snapshot halinde saklanmıyor

## 11. Snapshot Status

### Quote snapshot status

Güçlü.

Kanıtlar:

- `QuoteCurrencyPricingService::buildItemPricing()`
- `CatalogSearchController::safeQuotePriceSnapshot()`
- `PromotionQuoteCurrencySnapshotTest`
- `QuoteSendSnapshotBuilder`

Saklanan ana alanlar:

- `source_price`
- `source_currency`
- `tenant_base_currency`
- `document_currency`
- `suggested_sales_unit_price_document`
- `actual_sales_unit_price_document`
- `manual_sales_price_override`
- `applied_rate`
- `rate_source`
- `rate_type`
- `rate_date`
- `fallback_used`
- `stale`

`PromotionQuoteCurrencySnapshotTest` ayrıca şunları kanıtlıyor:

- multi-currency kapalıysa document currency TRY'ye zorlanıyor
- manual sales override refresh sırasında korunuyor
- send-to-customer sonrası currency snapshot lock oluşuyor

### Procurement snapshot status

Zayıf.

Mevcut procurement item persistence:

- `purchase_list_price`
- `discount_rate`
- `purchase_unit_price`
- `purchase_total`

Eksik olanlar:

- `purchase_list_currency_original`
- `purchase_fx_rate`
- `purchase_fx_rate_date`
- `purchase_list_amount_try`
- `purchase_calculated_unit_try`
- `purchase_manual_unit_try`
- `purchase_final_unit_try`
- `manual_override_flag`
- `manual_override_reason`

JSON olarak `OrderItemProcurement.snapshot` ve `procurement_snapshot` mevcut olsa da repo içinde procurement item için zorunlu canonical contract görünmüyor.

## 12. Current Account Calculation

`app/Services/SupplierProcurementCurrentAccountSyncService.php` kanıtı:

- `mapItemToTransactionData()` amount olarak `purchase_total` kullanıyor
- quantity dolaylı olarak procurement item total hesabından geliyor
- transaction currency olarak:
  - `order.currency`
  - yoksa `request.tenant.default_currency`
  - yoksa `TRY`
  kullanılıyor

Bu nedenle:

- supplier debit currency her zaman supplier original currency değildir
- USD/EUR supplier procurement, current account tarafında order currency bias taşıyabilir

Duplicate prevention:

- `findExistingTransactionForItem()`
- eşleştirme: `tenant_account_id + source_type + source_id + transaction_type`
- yeni kayıt yerine mevcut transaction update ediliyor

Bu kısım idempotency açısından iyi, currency truth açısından eksik.

## 13. Existing Data Risk

Riskler:

- Eski procurement satırlarında only-TL sayı kalmış olabilir.
- Supplier original currency procurement satırında hiç snapshot alınmamış olabilir.
- Quote/order item price snapshot içinde satış source truth varken procurement ekranı onu yanlış kaynak olarak kullanıyor olabilir.
- Old procurement records için güvenilir backfill her kayıtta mümkün görünmüyor.

Güvenli karar:

- provenance yoksa eski procurement kaydına sessiz USD/EUR/TRY ataması yapılmamalı
- UI'da gerekirse `Eski kayıt — kaynak para birimi snapshot'ı yok` durumu gösterilmeli

## 14. Canonical Target Contract

### Supplier source truth

Mantıksal sözleşme:

- `supplier_list_amount_original`
- `supplier_list_currency`
- `supplier_price_updated_at`
- `supplier_source_id`
- `supplier_product_or_variant_id`

Gerçek repo eşlemesi bugün en yakın olarak:

- `SupplierProductRaw.purchase_price` / `source_price`
- `SupplierProductRaw.currency` / `source_currency`
- `StandardProduct.purchase_price` / `purchase_currency`
- `ProductPriceSnapshot.purchase_price` / `purchase_currency`

### Sales snapshot truth

Bugünkü quote snapshot bunu büyük oranda karşılıyor:

- `sales_list_amount_original` -> `price_snapshot.source_price`
- `sales_list_currency` -> `price_snapshot.source_currency`
- `sales_fx_rate` -> `price_snapshot.applied_rate`
- `sales_fx_rate_date` -> `price_snapshot.rate_date`
- `sales_list_amount_try` -> `price_snapshot.base_price`
- `sales_calculated_unit_try/document` -> `suggested_sales_unit_price_*`
- `sales_manual_unit_try/document` -> `actual_sales_unit_price_*` + `manual_sales_price_override`

### Procurement snapshot truth

Bugünkü persistence bunu karşılamıyor. Yeni canonical snapshot gerekir:

- `purchase_list_amount_original`
- `purchase_list_currency`
- `purchase_fx_rate`
- `purchase_fx_rate_date`
- `purchase_list_amount_try`
- `purchase_discount_percent`
- `purchase_calculated_unit_try`
- `purchase_manual_unit_try`
- `purchase_final_unit_try`
- `projected_purchase_total_try`
- `actual_purchase_total_try`
- `manual_override_flag`
- `manual_override_reason`

## 15. Required Schema Changes

Bu faz read-only olduğu için schema yazılmadı. Ancak implementation için migration ihtiyacı kanıtlandı.

Gerekli yön:

1. `supplier_procurement_request_items` üstüne canonical purchase snapshot alanları eklenmeli veya tek canonical JSON snapshot + select scalar alanları tanımlanmalı.
2. Current account transaction meta'sında procurement FX snapshot izi tutulmalı.
3. Historical procurement correction akışının aynı transaction'ı update etmesi korunmalı.

Minimum migration ihtiyacı:

- procurement original amount
- procurement original currency
- procurement fx rate
- procurement fx rate date
- procurement calculated/manual/final unit fields
- procurement snapshot version / manual override flag

## 16. Required Service Changes

1. `SupplierProcurementRequestDataBuilder`
   - `suggestPurchaseListPrice()` kaldırılmamalıysa bile supplier canonical source dışına taşmayan deterministic bir resolver'a bölünmeli.
   - Sales-side `orderItem.price_snapshot.list_price` procurement list source fallback'ından çıkarılmalı.

2. Yeni procurement price/currency resolver
   - supplier source truth
   - procurement date FX snapshot
   - TRY conversion
   - manual override merge
   üreten ayrı servis gereklidir.

3. `SupplierProcurementCurrentAccountSyncService`
   - supplier debit amount kaynağı explicit procurement final snapshot olmalı
   - currency doğrudan procurement settlement contract'tan gelmeli
   - meta içinde original amount/currency/rate izlenmeli

4. `PromotionQuoteController`
   - mevcut quote snapshot mantığı procurement'a kopyalanmamalı
   - sales truth ile purchase truth ayrı resolver'larda kalmalı

## 17. Required UI Changes

### Quote UI

- `Liste` başlığı yerine `Satış Liste Fiyatı`
- USD/EUR ürünlerde:
  - original amount/currency
  - kur
  - TL karşılığı
  birlikte görünmeli
- `Birim Satış Fiyatı` editable kalabilir

### Procurement UI

- `Tedarikçi Liste Fiyatı` açık etiket olmalı
- `Para Birimi`, `Kur`, `TL Karşılığı` ayrı görünmeli
- `Alış Birim Fiyatı` yalnız yetkili override yüzeyi olmalı
- satış referansı yardımcı bilgi olabilir; source truth olamaz

## 18. Test Matrix

Zorunlu test grupları:

1. TRY satış
   - list price
   - discount
   - calculated unit
   - manual final
   - snapshot lock

2. USD/EUR satış
   - original amount/currency görünür
   - FX snapshot persisted
   - refresh manual override bozmuyor

3. TRY procurement
   - supplier list source sales list'ten bağımsız
   - calculated purchase unit doğru
   - manual override ayrı işaretli

4. USD/EUR procurement
   - original supplier amount/currency görünür
   - procurement kendi FX snapshot'ını alır
   - sales source fallback yasak

5. Sales vs purchase divergence
   - sales list 10.00 USD
   - supplier list 7.75 USD
   - quote ve procurement farklı truth kullanır

6. Current account
   - same source item update idempotent
   - final amount received quantity ile uyumlu
   - transaction meta historical snapshot taşır

## 19. Migration / Backfill Plan

Backfill bu fazda güvenli değildir.

Plan:

1. Yeni canonical procurement snapshot şeması ekle.
2. Yeni kayıtlar için write-path'i canonical hale getir.
3. Eski kayıtlar için provenance sınıflandır:
   - `trusted procurement snapshot exists`
   - `only sales snapshot exists`
   - `only TL scalar values exist`
4. Yalnız `trusted procurement snapshot exists` grubunda deterministic backfill düşün.
5. Provenance belirsiz eski kayıtlarda tahmini currency ataması yapma.

Karar:

- `Backfill safe: NO`

## 20. Implementation Phases

Önerilen sıralı fazlar:

1. `F1P1 — Canonical price/currency contract and snapshot tests`
2. `F1P2 — Quote sales list/currency UI and calculation`
3. `F1P3 — Procurement supplier list/currency UI and calculation`
4. `F1P4 — Current account idempotency and historical snapshot hardening`
5. `F1P5 — Manual smoke and selective commits`

Tek büyük commit önerilmez.

## 21. GO / CONDITIONAL GO / NO-GO

Karar: `CONDITIONAL GO`

Anlamı:

- Audit tamamlandı.
- Root cause ve risk alanları kanıtlandı.
- Güvenli implementation planı hazır.
- Ancak mevcut F1R/F1H2 procurement diffleri bu haliyle commit-ready değildir.

Stop-line sonucu:

- sales list source net: `YES`
- supplier list source net: `NO, mixed fallback chain`
- original currency provenance net: `SALES YES / PROCUREMENT NO`
- procurement sales price kullanma riski: `YES`
- current account price source net: `PARTIAL`
- current account currency contract net: `NO`
- migration ihtiyacı kanıtlandı: `YES`

Bu yüzden audit sonrası karar:

`Do not commit procurement pricing changes before F1P1-F1P4 contract hardening.`

## 22. Console Summary

- A) Preflight: `PASS`
- B) Supplier source list field: `SupplierProductRaw.purchase_price/source_price and StandardProduct.purchase_price are candidate truths`
- C) Supplier currency field: `SupplierProductRaw.currency/source_currency and StandardProduct.purchase_currency`
- D) Tenant sales list field: `TenantCatalogProduct.display_price/sale_price + Product Hub normalized source_price/source_currency`
- E) Quote list source: `Product Hub / catalog snapshot -> QuoteCurrencyPricingService`
- F) Procurement list source: `Mixed fallback chain; not canonical`
- G) Currency loss point: `Procurement item persistence and procurement source resolver`
- H) Quote FX snapshot: `Present`
- I) Procurement FX snapshot: `Not canonical / not persisted on procurement item`
- J) Manual sales override: `Present and tested`
- K) Manual purchase override: `Calculation exists, persistence contract incomplete`
- L) Current account price source: `purchase_total`
- M) Current account quantity source: `Indirectly procurement total; completed received-qty contract not explicit`
- N) Duplicate prevention: `source_type + source_id + transaction_type update path`
- O) Historical data risk: `High for procurement provenance`
- P) Migration needed: `Yes`
- Q) Backfill safe: `No`
- R) Production changed: `No`
- S) Staging: `No`
- T) Commit: `No`
- U) Report path: `docs/SALES-PROCUREMENT-PRICE-CURRENCY-TRUTH-AUDIT-REPORT-20260714.md`
- V) Implementation recommendation: `F1P1 -> F1P5 phased`
- W) Final decision: `VERIFIED — SALES / PROCUREMENT PRICE AND CURRENCY TRUTH MAPPED — IMPLEMENTATION GATE READY`
