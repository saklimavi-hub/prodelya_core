# PRODELYA M13-J — Procurement Draft Price Refresh Hotfix Raporu — 2026-07-22

## Kapsam
- Dar kapsam: Procurement draft purchase price refresh ve bu akışın canonical supplier price / FX fixture regresyonları.
- Kapsam dışı korundu: Production V1, schema/migration, global CSS, M14, staging/commit/tag.
- Staged alan kullanılmadı; dirty worktree'deki ilgisiz dosyalar korunmuştur.

## Reproduction
İlk koşu `ProcurementDraftPriceRefreshTest` içinde canonical USD fixture için edit ekranının final alış birim fiyatını boş render ettiğini gösterdi:
- supplier liste: `3,50 USD`
- beklenen input: `value="164.49"`
- beklenen calculated value: `164.488100`
- görülen durum: FX eksik uyarısı, boş calculated/final value

## 164.49 Kanıtı
Canonical fixture kaynağı:
- supplier raw price: `SupplierProductRaw.purchase_price = 3.5000`
- source currency: `USD`
- supplier product code: `PZ-CH60SY`
- snapshot attribution: `product_snapshot.supplier_product_raw_id`
- FX provider/type/date: `tcmb / forex_selling / 2026-07-14`
- FX rate: `1 USD = 46.99660000 TRY`

Aritmetik:
- `3.5000 * 46.99660000 = 164.488100 TRY`
- discount: `0%`
- effective final unit: `164.488100`
- HTML number input display: `164.49`
- quantity `10` total: `164.488100 * 10 = 1644.881000`, two-decimal total `1644.88`

Bu nedenle test beklentisi fixture truth ile tutarlıdır; beklenen değer hardcode edilmedi veya değiştirilmedi.

## Root Cause
1. Test fixture'ları yalnız `2026-07-14` tarihli FX rate seed ediyordu. Güncel test tarihi `2026-07-22`; procurement order quote date boş olduğunda canonical service `order.created_at/now` üstünden rate istiyor ve resolver 7 gün üzeri stale rate'i hard-fail ediyor. Sonuç `missing_fx_rate` ve boş UI değeriydi.
2. Draft refresh item purchase truth alanlarını doğru güncelliyordu, fakat legacy transaction amount request-level sync sonunda eski `1640.00` değerinde kalabiliyordu. Refresh sonrası değişen kalemler mevcut `SupplierProcurementCurrentAccountSyncService::syncRequestItem()` üzerinden doğrudan yeniden senkronize edildi.

## Uygulama
- `app/Services/SupplierProcurementRequestService.php`: `refreshLegacyDraftPurchaseTruth()` içinde gerçekten yenilenen item id'leri toplanıp, mevcut canonical item-level current-account sync servisiyle yeniden senkronize edildi.
- `tests/Feature/ProcurementDraftPriceRefreshTest.php`: canonical FX fixture quote date'i `2026-07-14` olarak pinlendi; refresh testine sales price/snapshot değişmezliği assertion'ları eklendi.
- İlgili procurement FX fixture testlerinde aynı tarih drift'i giderildi: purchase snapshot, supplier price label, presenter binding, source attribution, exact variant, supplier request price reference.

## Koruma Notları
- Manual override korunur: update/refresh path mevcut `ProcurementPurchasePricingService` manual unit override sözleşmesini kullanmaya devam eder.
- Tenant isolation korunur: refresh sync query `tenant_account_id` ve exact refreshed item id listesi ile sınırlandı.
- Sales prices korunur: focused refresh regression order item `unit_price`, `line_total`, `price_snapshot` alanlarının değişmediğini doğrular.
- Production V1 dosyalarına, schema'ya, global CSS'e veya M14 kapsamına dokunulmadı.

## Regresyon Matrisi
- PASS: `php artisan test --filter=ProcurementDraftPriceRefreshTest --stop-on-failure` — 4 tests, 33 assertions.
- PASS: `php artisan test --filter=Procurement --stop-on-failure` — 131 tests, 1840 assertions.
- PASS: `php artisan test --filter=SupplierProcurement --stop-on-failure` — 40 tests, 356 assertions.
- PASS: `php artisan test --filter=SupplierPrice --stop-on-failure` — 5 tests, 29 assertions.
- PASS: `php artisan view:clear`.
- PASS: `php artisan view:cache`.
- PASS: `php artisan test --filter=Production --stop-on-failure` — 165 tests, 2273 assertions.
- PASS: `php artisan test --filter=Order --stop-on-failure` — 263 tests, 2371 assertions.
- PASS: `php artisan test --filter=AdminSmokeTest --stop-on-failure` — 59 tests, 214 assertions.
- OUT OF SCOPE FAIL: `php artisan test --filter=TenantCurrency --stop-on-failure` stops at `TenantCurrencySettingsDiagnosticTest::test_currency_settings_permission_debug`, expected 200 but received 403. This is a currency settings diagnostic permission regression and outside this procurement draft price refresh hotfix.

## Git Safety
- No staging, commit, tag, reset, restore, stash or clean performed.
- `git diff --cached --name-only` returned empty.
- Existing unrelated dirty worktree changes were preserved.

READY — PROCUREMENT DRAFT PRICE REFRESH HOTFIX — MANUAL SMOKE REQUIRED
