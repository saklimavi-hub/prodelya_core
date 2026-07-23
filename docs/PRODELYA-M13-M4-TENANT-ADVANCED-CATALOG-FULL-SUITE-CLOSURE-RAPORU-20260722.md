# PRODELYA M13-M4 Tenant Advanced Catalog Full Suite Closure Raporu - 2026-07-22

Durum: READY -- TENANT ADVANCED CATALOG REGRESSIONS CLEARED -- FULL SUITE PASS

## Baseline

M13-M3 full suite sonucu iki TenantAdvancedCatalog failure ile bitiyordu:

- `TenantAdvancedCatalogTest::test_tenant_can_edit_and_deactivate_local_product`
  - Beklenen eski fixture: `stock_quantity = 15`
  - Gercek canonical sonuc: `stock_quantity = 50`
- `TenantAdvancedCatalogTest::test_supplier_purchase_uses_discount_calculation_and_manual_purchase_price`
  - Beklenen: `tenant_supplier_purchase_entries` satiri
  - Gercek baseline: tablo bos

## Local Product Stock Truth

Kanıtlanan davranis Scenario B'dir: local product edit endpoint'i metadata-only calisir; stok hareket-managed tutulur.

- `resources/views/admin/catalog/partials/_local-product-form.blade.php` edit modunda flat stok alanini input olarak gondermez, read-only hint gosterir.
- `TenantLocalProductWriteService::updateFlatProduct()` urun metadata ve gorsel bilgilerini gunceller.
- `TenantLocalProductWriteService::buildFlatProductPayload()` mevcut `stock_quantity`, `total_stock_quantity` ve `local_stock_quantity` degerlerini korur.
- Ilk stok yalniz create akisi icinde `CatalogFastStockActionService::store()` ile acilis stok kaydi olarak olusur.
- Stok girisi/satin alma icin canonical akış `CatalogFastStockActionService` uzerindedir.

Sonuc: Test, edit post icinde gelen `local_stock_quantity=15` degerinin stok truth'u degistirmedigini ve deactivate'in mevcut stock truth'u sifirlamadigini dogrulayacak sekilde duzeltildi.

## Supplier Purchase Truth

Root cause: failing fixture, supplier purchase yapmak istedigi urunu gercek bir tedarikci/source/access zincirine baglamiyordu. Canonical `CatalogFastStockActionService` tamamlanmis satin alma icin supplier identity ve `can_request_purchase` izni ister.

Fixture duzeltmesi:

- Aktif supplier olusturuldu.
- Ayni tenant icin `TenantSupplierAccess` aktif, gorunur ve purchase izinli kuruldu.
- Urun `source_summary` icinde exact supplier identity ile baglandi.

Hesap sirası:

- Supplier/list price: `100`
- Discount: `%45`
- Calculated purchase unit price: `100 * (1 - 45/100) = 55`
- Manual purchase unit price: `54.5` override eder
- Quantity: `2`
- Purchase payable amount: `54.5 * 2 = 109`

Canonical service sonucunda:

- Tek `tenant_supplier_purchase_entries` satiri olusur.
- Tek `stock_movements` purchase-in satiri olusur.
- Product local stock `0 -> 2`, total/stock projection `50 -> 52` olur.
- Sales price/display price `100` korunur.

Controller icinde fake purchase entry olusturulmadi.

## Degisen Dosya

- `tests/Feature/TenantAdvancedCatalogTest.php`

## Test Sonuclari

- `php -l tests\Feature\TenantAdvancedCatalogTest.php`: PASS
- `php artisan view:clear`: PASS
- `php artisan view:cache`: PASS
- `php artisan test --filter="TenantAdvancedCatalogTest::test_tenant_can_edit_and_deactivate_local_product" --stop-on-failure`: PASS, 1 test, 5 assertions
- `php artisan test --filter="TenantAdvancedCatalogTest::test_supplier_purchase_uses_discount_calculation_and_manual_purchase_price" --stop-on-failure`: PASS, 1 test, 4 assertions
- `php artisan test --filter=TenantAdvancedCatalogTest --stop-on-failure`: PASS, 17 tests, 96 assertions
- `php artisan test --filter=TenantCatalog --stop-on-failure`: PASS, 15 tests, 133 assertions
- `php artisan test --filter=LocalProduct --stop-on-failure`: PASS, 29 tests, 235 assertions
- `php artisan test --filter=StockPurchase --stop-on-failure`: PASS, 15 tests, 72 assertions
- `php artisan test --filter=SupplierPurchase --stop-on-failure`: no matching tests
- `php artisan test --filter=ProductHub --stop-on-failure`: PASS, 99 tests, 630 assertions
- `php artisan test --filter=ProductDataHub --stop-on-failure`: PASS, 269 tests, 1804 assertions
- `php artisan test --filter=Procurement --stop-on-failure`: PASS, 131 tests, 1840 assertions
- `php artisan test --filter=PromotionQuote --stop-on-failure`: PASS, 197 tests, 1663 assertions
- `php artisan test --filter=Order --stop-on-failure`: PASS, 263 tests, 2371 assertions
- `php artisan test --filter=Production --stop-on-failure`: PASS, 165 tests, 2273 assertions
- `php artisan test --filter=AdminSmokeTest --stop-on-failure`: PASS, 59 tests, 214 assertions
- `php artisan test --log-junit .tmp\m13-m4-full-suite.xml`: PASS, 2213 tests, 2213 passed, 21523 assertions, 0 failures, 0 errors

## Manual Smoke Notes

- Local product edit: metadata fields update; stock remains movement-managed/read-only.
- Deactivate: active/catalog/quote visibility close; stock history/current values remain intact.
- Supplier purchase: supplier identity, discount, manual override, stock movement, purchase entry, and current-account sync stay on canonical `CatalogFastStockActionService`.

## Git State

- Staging/commit/tag/reset/restore/stash/clean not used.
- No schema, global CSS, M14, Product Hub, Production, Procurement, Quote, or Order behavior changed for this batch.
